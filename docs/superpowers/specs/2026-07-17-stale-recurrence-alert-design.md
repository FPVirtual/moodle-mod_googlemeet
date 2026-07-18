# Diseño: aviso de recurrencia googlemeet abandonada

- **Fecha:** 2026-07-17
- **Plugin:** `mod_googlemeet`
- **Estado:** aprobado (brainstorming), pendiente de plan de implementación

## Problema

Cuando un curso con clases en directo termina, su actividad `googlemeet` puede quedarse con la
**recurrencia activa** (`eventenddate` en el futuro). El plugin sigue generando sesiones futuras y
las **espeja en el calendario nativo de Moodle** (`mdl_event`), que aparecen en "Próximos eventos"
de todos los alumnos matriculados → **avisos de clases fantasma**.

El `notify` de la instancia NO evita esto (gobierna solo el recordatorio propio del plugin
`notify_event`, filtrado por `AND m.notify = 1`; el espejo de calendario se escribe siempre). Y la
sincronización con Google Calendar es **unidireccional Moodle→Google** (solo `insertevent`/delete en
`rest.php`, sin `events.watch`/webhook), así que cerrar el evento en Google Calendar tampoco se
propaga de vuelta.

Caso real que motivó esto: curso IBSALUT (gmid 3), clases terminadas en junio pero recurrencia hasta
el 27-jul generando sesiones 21/23/28-jul visibles a 104 alumnos. Se corrigió a mano adelantando la
fecha fin. Este diseño previene la reincidencia **avisando de forma proactiva**.

## Objetivo

Una tarea programada que detecte actividades googlemeet cuya recurrencia parece **abandonada** y
**avise a los administradores** para que decidan cerrarla. Sin cierre automático.

## Fuera de alcance (YAGNI)

- Cierre automático de la recurrencia (la decisión siempre es del admin).
- Cualquier integración/lectura de Google Calendar (hook inverso): descartado por coste/fragilidad
  (canales que caducan, OAuth por usuario, modelos que no casan 1:1). Fuente de verdad = Moodle.
- Vista/informe on-demand en Admin: posible fase 2, no ahora.

## Criterio de detección ("abandonada")

Una instancia googlemeet se marca **abandonada** cuando se cumplen **las tres**:

1. Tiene **≥1 sesión futura**: `EXISTS googlemeet_events WHERE googlemeetid = g.id AND eventdate > now`.
2. Ha **grabado alguna vez** (histórico real): `EXISTS googlemeet_recordings WHERE googlemeetid = g.id AND deleted = 0`.
3. **Lleva ≥ N semanas sin grabar**: `now − MAX(createdtime de grabaciones no borradas) > N * 7 * DAYSECS`.

Notas de diseño del criterio:
- La condición (2) evita falsos positivos en cursos que **nunca** graban (clases en directo sin
  grabación) — esos no tienen historial y no se marcan.
- La condición (1) evita marcar cursos ya cerrados correctamente (sin sesiones futuras, p. ej. csif).
- N por defecto = **3 semanas** (configurable). Con 2 clases/semana ≈ 6 sesiones seguidas sin grabar.

## Componentes

### 1. Tarea programada `\mod_googlemeet\task\check_stale_recurrence`
- Registrada en `db/tasks.php`, frecuencia **semanal**, en franja de mínima actividad
  (default: **lunes 04:00**; `minute=0 hour=4 day=* month=* dayofweek=1`).
- Respeta el ajuste `stalerecurrence_enabled` (si off, retorna sin hacer nada).
- Recorre las instancias, aplica el criterio, y para cada abandonada decide envío según el estado
  anti-spam (ver abajo). Usa `get_recordset_sql` si se hace en una sola consulta (evitar el gotcha de
  `get_records_sql` indexando por 1ª columna).
- Lógica de detección aislada en un método/función testeable que devuelve la lista de instancias
  abandonadas con sus datos (nombre, curso, cmid, última grabación, nº sesiones futuras), separada
  del envío de mensajes.

### 2. Ajustes (`settings.php`, plugin `googlemeet`)
- `stalerecurrence_enabled` — checkbox, default **1**.
- `stalerecurrence_weeks` — número entero, default **3**.
- `stalerecurrence_renotifydays` — número entero, default **28** (re-aviso si sigue abandonada).
- ⚠️ Gotcha del plugin: los defaults de `settings.php` no se persisten hasta guardar la página → si
  el código lee con `get_config` y necesita el default garantizado, usar `get_config(...) ?: <def>` o
  `set_config` en `upgrade.php`.

### 3. Message provider (`db/messages.php` → `stalerecurrence`)
- Nuevo provider; strings en `lang/en` y `lang/es`.
- Envío con `message_send()` a cada admin de `get_admins()`.
- Contenido: nombre de la actividad + curso, fecha de la última grabación, nº de sesiones futuras, y
  **enlace directo** a `/course/modedit.php?update=<cmid>` con la instrucción: *"para cerrarla, pon
  'Repetir hasta' en una fecha pasada"*. Versión HTML + texto plano.

### 4. Estado anti-spam (sin tabla nueva)
- Por instancia, config del plugin: `stalealert_<gmid>` = timestamp del último aviso.
- En cada pasada, por instancia abandonada:
  - Si **no** hay registro **o** `now − registro > renotifydays` → **envía** y guarda `now`.
  - Si no supera el umbral de re-aviso → **omite** (ya avisado hace poco).
- Por instancia **no** abandonada: si existe `stalealert_<gmid>` → **borra** el registro
  (`unset_config`), de modo que el aviso se rearma para un futuro episodio.
- Se justifica no crear tabla: nº de instancias pequeño; el estado es efímero y auto-limpiable.

## Flujo de datos

```
cron semanal → check_stale_recurrence::execute()
  ├─ if !enabled: return
  ├─ detectar_abandonadas(weeks)  → [ {gmid, cmid, course, name, lastrec, futuras} ]
  │     (consulta única read-only sobre googlemeet + events + recordings)
  ├─ por cada abandonada:
  │     estado = get_config('googlemeet', "stalealert_$gmid")
  │     if !estado || now-estado > renotifydays: message_send(admins); set_config(now)
  ├─ por cada NO abandonada con estado previo: unset_config("stalealert_$gmid")
  └─ mtrace del resumen (n detectadas / n avisadas)
```

## Manejo de errores
- Envío de mensajes por instancia en su propio try/catch: un fallo de envío a un admin no aborta el
  resto ni el barrido. Registrar con `mtrace`/`debugging`.
- Consulta de detección read-only; si falla, la excepción propaga (la tarea reintenta en el próximo
  cron con backoff estándar de Moodle).

## Testing (PHPUnit del plugin)
Fixtures y aserciones sobre la lógica de detección + anti-spam (con `message` sink de Moodle):
- (a) futuras + grabación de hace > N semanas → **detectada y avisa**.
- (b) futuras + grabación reciente (< N semanas) → **no**.
- (c) grabación vieja pero **sin** sesiones futuras → **no**.
- (d) futuras pero **nunca** grabó → **no**.
- (e) anti-spam: 2ª ejecución consecutiva (dentro de `renotifydays`) → **no reenvía**.
- (f) re-aviso: si el último aviso fue hace > `renotifydays` y sigue abandonada → **reenvía**.
- (g) auto-limpieza: instancia que deja de estar abandonada → se borra `stalealert_<gmid>`.
- Nota entorno PHPUnit del box: reconstruir test DB con `util.php --drop/--install` tras el bump.

## Deploy (gotchas del plugin)
- `rsync -a --exclude=.git --exclude=CLAUDE.md` del repo a `formacion51/public/mod/googlemeet` (sin `--delete`).
- `php admin/cli/upgrade.php --non-interactive` (registra tarea + provider; el **bump de `version.php`
  lo hace el controlador de deploy — codex NO toca `version.php`**).
- **opcache reset** tras copiar `.php` (script autoborrable dentro de `public/` vía HTTPS) + `purge_caches.php`.
- Verificar provider instalado (`get_string` de las cadenas resuelve) y la tarea visible en
  Admin → Servidor → Tareas programadas con `faildelay=0`.

## Entregable adicional: README
Actualizar `README.md` del repo documentando la nueva función:
- Sección nueva ("Aviso de recurrencia abandonada") explicando qué detecta, el criterio, los tres
  ajustes (`stalerecurrence_enabled`/`weeks`/`renotifydays`) y a quién avisa.
- Nota sobre la arquitectura de calendario (Moodle es la fuente de verdad; sync Moodle→Google
  unidireccional) para que quede claro por qué el cierre se hace en Moodle.

## Criterios de aceptación
- La tarea aparece en Tareas programadas y corre sin error.
- Con una instancia que cumple el criterio, los admins reciben **una** notificación (no una por
  ejecución) con enlace de edición correcto.
- Cerrar la recurrencia (o volver a grabar) hace que deje de avisar y limpia el estado.
- Tests PHPUnit del plugin en verde.
- README actualizado.
