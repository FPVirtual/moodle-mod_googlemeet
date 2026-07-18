# Aviso de recurrencia googlemeet abandonada — Plan de implementación

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking. En este proyecto la cadena preferida es **codex programa → Opus revisa → Claude cierra** (ver memoria `feedback_codex_xhigh_programa_opus_revisa_claude_final`).

**Goal:** Una tarea programada semanal que detecte actividades `mod_googlemeet` cuya recurrencia parece abandonada (siguen agendando sesiones futuras pero llevan N semanas sin grabar) y avise a los administradores del sitio, sin cerrar nada automáticamente.

**Architecture:** Función de detección read-only en `locallib.php` (aislada y testeable) + función de envío que usa el sistema de mensajería de Moodle a `get_admins()` + una `scheduled_task` que orquesta detección, anti-spam (estado en `config_plugins`) y auto-limpieza. Tres ajustes nuevos en `settings.php` y un `messageprovider` nuevo en `db/messages.php`.

**Tech Stack:** PHP / Moodle 5.1 plugin API (`\core\task\scheduled_task`, `\core\message\message`, `admin_setting_*`, `get_recordset_sql`), PHPUnit (`advanced_testcase`, `redirectMessages`).

## Global Constraints

- Moodle 5.1, webroot `public/`; plugin vive en `~/desarrollo/moodle-mod_googlemeet`, se despliega a `~/formacion51/public/mod/googlemeet`.
- **NO tocar `version.php`** en las tareas de código: el bump lo hace el controlador de deploy (memoria `googlemeet_improvement_f0_f2_2026_07_02`).
- Usar `get_recordset_sql` (no `get_records_sql`) para conjuntos con posible GROUP/BY o multi-fila: `get_records_sql` indexa por 1ª columna y puede perder filas (memoria MEMORY.md).
- Los defaults de `settings.php` NO se persisten hasta guardar la página → el código lee con guarda: `(int) get_config(...)` + fallback si `<= 0`/`false`.
- Funciones de `locallib.php` son **globales** (sin namespace), no autocargadas: los tests hacen `require_once` de `lib.php` + `locallib.php`.
- Cadenas en `lang/en/googlemeet.php` **y** `lang/es/googlemeet.php` (paridad).
- Idempotencia/estilo: seguir el patrón de las tareas existentes (`classes/task/notify_event.php`) y de los settings existentes.

---

## Estructura de ficheros

- **Modificar** `locallib.php` — añadir `googlemeet_get_stale_recurrences()` y `googlemeet_send_stale_alert()` (funciones globales, junto al resto de helpers).
- **Crear** `classes/task/check_stale_recurrence.php` — la `scheduled_task`.
- **Modificar** `db/tasks.php` — registrar la tarea (semanal, lunes 04:00).
- **Modificar** `db/messages.php` — añadir provider `stalerecurrence`.
- **Modificar** `settings.php` — heading + 3 ajustes.
- **Modificar** `lang/en/googlemeet.php` y `lang/es/googlemeet.php` — cadenas.
- **Crear** `tests/stale_recurrence_test.php` — tests de detección, anti-spam y envío.
- **Modificar** `README.md` — documentar la función.

---

### Task 1: Función de detección `googlemeet_get_stale_recurrences()`

**Files:**
- Modify: `locallib.php` (añadir función global)
- Test: `tests/stale_recurrence_test.php`

**Interfaces:**
- Produces: `googlemeet_get_stale_recurrences(int $weeks, ?int $now = null): array` — devuelve array de `stdClass` con campos `{id, name, course, coursename, cmid, lastrecording, futurecount}`. `id` = `googlemeet.id` (instancia); `cmid` = `course_modules.id`.

- [ ] **Step 1: Escribir el test que falla**

En `tests/stale_recurrence_test.php`:

```php
<?php
namespace mod_googlemeet;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/lib.php');
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Tests for the abandoned-recurrence detector + alert task.
 *
 * @package     mod_googlemeet
 * @category    test
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class stale_recurrence_test extends \advanced_testcase {

    /** Insert a googlemeet_events row. */
    private function add_event(int $gmid, int $eventdate): void {
        global $DB;
        $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $gmid,
            'eventdate' => $eventdate,
            'duration' => 7200,
            'timemodified' => $eventdate,
            'autosynced' => 0,
            'syncattempts' => 0,
        ]);
    }

    /** Insert a googlemeet_recordings row. */
    private function add_recording(int $gmid, int $createdtime, int $deleted = 0): void {
        global $DB;
        $DB->insert_record('googlemeet_recordings', (object) [
            'googlemeetid' => $gmid,
            'recordingid' => 'rec-' . $gmid . '-' . $createdtime,
            'name' => 'Recording',
            'createdtime' => $createdtime,
            'duration' => '1:00:00',
            'webviewlink' => 'https://drive.google.com/file/d/x/view',
            'transcripttext' => '',
            'transcriptfileid' => '',
            'notestext' => '',
            'notesdocid' => '',
            'visible' => 1,
            'deleted' => $deleted,
            'timedeleted' => 0,
            'timemodified' => $createdtime,
        ]);
    }

    /** Create a googlemeet module and return its instance record (has ->id and ->cmid). */
    private function make_module(): \stdClass {
        $course = $this->getDataGenerator()->create_course();
        return $this->getDataGenerator()->create_module('googlemeet', ['course' => $course->id]);
    }

    public function test_detects_abandoned_recurrence(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);          // future session
        $this->add_recording($gm->id, $now - 30 * DAYSECS); // last recording 30d ago

        $stale = googlemeet_get_stale_recurrences(3, $now);

        $this->assertCount(1, $stale);
        $this->assertEquals($gm->id, $stale[0]->id);
        $this->assertEquals($gm->cmid, $stale[0]->cmid);
        $this->assertEquals(1, (int) $stale[0]->futurecount);
    }
}
```

- [ ] **Step 2: Ejecutar y ver que falla**

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php --filter test_detects_abandoned_recurrence`
Expected: FAIL con `Call to undefined function ...googlemeet_get_stale_recurrences()`.

- [ ] **Step 3: Implementar la función**

Añadir en `locallib.php` (entre otros helpers globales):

```php
/**
 * Find googlemeet instances whose recurrence looks abandoned: they still schedule future
 * sessions and have recorded at least once, but have had no recording in the last $weeks weeks.
 *
 * Read-only. Uses a recordset (not get_records_sql) to avoid first-column indexing pitfalls.
 *
 * @param int $weeks Staleness threshold in weeks.
 * @param int|null $now Reference timestamp (defaults to time()); injectable for tests.
 * @return array Array of stdClass {id, name, course, coursename, cmid, lastrecording, futurecount}.
 */
function googlemeet_get_stale_recurrences(int $weeks, ?int $now = null): array {
    global $DB;

    $now = $now ?? time();
    $moduleid = $DB->get_field('modules', 'id', ['name' => 'googlemeet'], MUST_EXIST);

    $sql = "SELECT g.id, g.name, g.course, c.fullname AS coursename, cm.id AS cmid,
                   (SELECT MAX(r.createdtime)
                      FROM {googlemeet_recordings} r
                     WHERE r.googlemeetid = g.id AND r.deleted = 0) AS lastrecording,
                   (SELECT COUNT(1)
                      FROM {googlemeet_events} e
                     WHERE e.googlemeetid = g.id AND e.eventdate > :now1) AS futurecount
              FROM {googlemeet} g
              JOIN {course_modules} cm ON cm.instance = g.id AND cm.module = :moduleid
              JOIN {course} c ON c.id = g.course
             WHERE EXISTS (SELECT 1 FROM {googlemeet_events} e2
                            WHERE e2.googlemeetid = g.id AND e2.eventdate > :now2)
               AND EXISTS (SELECT 1 FROM {googlemeet_recordings} r2
                            WHERE r2.googlemeetid = g.id AND r2.deleted = 0)";
    $params = ['now1' => $now, 'now2' => $now, 'moduleid' => $moduleid];

    $threshold = $weeks * 7 * DAYSECS;
    $stale = [];
    $rs = $DB->get_recordset_sql($sql, $params);
    foreach ($rs as $row) {
        if ($row->lastrecording !== null && ($now - (int) $row->lastrecording) > $threshold) {
            $stale[] = $row;
        }
    }
    $rs->close();

    return $stale;
}
```

- [ ] **Step 4: Ejecutar y ver que pasa**

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php --filter test_detects_abandoned_recurrence`
Expected: PASS.

- [ ] **Step 5: Añadir tests de los casos negativos y ejecutarlos**

Añadir al test class:

```php
    public function test_ignores_recent_recording(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 5 * DAYSECS); // recent
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_ignores_when_no_future_sessions(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now - 10 * DAYSECS); // only past
        $this->add_recording($gm->id, $now - 60 * DAYSECS);
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_ignores_when_never_recorded(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        // no recordings at all
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }

    public function test_ignores_when_only_deleted_recordings(): void {
        $this->resetAfterTest();
        $now = 1800000000;
        $gm = $this->make_module();
        $this->add_event($gm->id, $now + DAYSECS);
        $this->add_recording($gm->id, $now - 60 * DAYSECS, 1); // deleted
        $this->assertCount(0, googlemeet_get_stale_recurrences(3, $now));
    }
```

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add locallib.php tests/stale_recurrence_test.php
git commit -m "feat(googlemeet): detect abandoned recurrences (still scheduling, long unrecorded)"
```

---

### Task 2: Cadenas de idioma (en + es)

**Files:**
- Modify: `lang/en/googlemeet.php`
- Modify: `lang/es/googlemeet.php`

**Interfaces:**
- Produces: claves de string `stalerecurrence_task`, `messageprovider:stalerecurrence`, `stalerecurrence_heading`, `stalerecurrence_heading_desc`, `stalerecurrence_enabled`, `stalerecurrence_enabled_desc`, `stalerecurrence_weeks`, `stalerecurrence_weeks_desc`, `stalerecurrence_renotifydays`, `stalerecurrence_renotifydays_desc`, `stalerecurrence_subject`, `stalerecurrence_body`, `stalerecurrence_body_html`, `stalerecurrence_editlink`.

- [ ] **Step 1: Añadir las cadenas en inglés**

En `lang/en/googlemeet.php` (respetando el orden alfabético aproximado del fichero):

```php
$string['messageprovider:stalerecurrence'] = 'Abandoned live-class recurrence alert';
$string['stalerecurrence_task'] = 'Check for abandoned Google Meet recurrences';
$string['stalerecurrence_heading'] = 'Abandoned recurrence alerts';
$string['stalerecurrence_heading_desc'] = 'Warn site admins when a Google Meet activity keeps scheduling future sessions but stopped recording — a sign its live classes ended and the recurrence should be closed.';
$string['stalerecurrence_enabled'] = 'Enable abandoned-recurrence alerts';
$string['stalerecurrence_enabled_desc'] = 'Run a weekly check and notify site admins about abandoned recurrences.';
$string['stalerecurrence_weeks'] = 'Weeks without recording';
$string['stalerecurrence_weeks_desc'] = 'How many weeks with no new recording (while future sessions are still scheduled) before an activity is flagged as abandoned.';
$string['stalerecurrence_renotifydays'] = 'Re-notify after (days)';
$string['stalerecurrence_renotifydays_desc'] = 'If an activity stays abandoned, remind admins again after this many days.';
$string['stalerecurrence_subject'] = 'Google Meet recurrence looks abandoned: {$a->activity}';
$string['stalerecurrence_body'] = 'The activity "{$a->activity}" in course "{$a->course}" still has {$a->futurecount} future session(s) scheduled, but its last recording was on {$a->lastrecording}. If its live classes have ended, close the recurrence by setting "Repeat until" to a past date here: {$a->editurl}';
$string['stalerecurrence_body_html'] = 'The activity "<strong>{$a->activity}</strong>" in course "<strong>{$a->course}</strong>" still has <strong>{$a->futurecount}</strong> future session(s) scheduled, but its last recording was on <strong>{$a->lastrecording}</strong>.<br>If its live classes have ended, close the recurrence by setting <em>Repeat until</em> to a past date: <a href="{$a->editurl}">edit the activity</a>.';
$string['stalerecurrence_editlink'] = 'Edit the activity';
```

- [ ] **Step 2: Añadir las cadenas en español**

En `lang/es/googlemeet.php`:

```php
$string['messageprovider:stalerecurrence'] = 'Aviso de recurrencia de clases en directo abandonada';
$string['stalerecurrence_task'] = 'Comprobar recurrencias de Google Meet abandonadas';
$string['stalerecurrence_heading'] = 'Avisos de recurrencia abandonada';
$string['stalerecurrence_heading_desc'] = 'Avisa a los administradores cuando una actividad de Google Meet sigue programando sesiones futuras pero dejó de grabar — señal de que sus clases en directo terminaron y hay que cerrar la recurrencia.';
$string['stalerecurrence_enabled'] = 'Activar avisos de recurrencia abandonada';
$string['stalerecurrence_enabled_desc'] = 'Ejecuta una comprobación semanal y notifica a los administradores sobre recurrencias abandonadas.';
$string['stalerecurrence_weeks'] = 'Semanas sin grabar';
$string['stalerecurrence_weeks_desc'] = 'Cuántas semanas sin grabación nueva (con sesiones futuras aún programadas) antes de marcar una actividad como abandonada.';
$string['stalerecurrence_renotifydays'] = 'Reavisar tras (días)';
$string['stalerecurrence_renotifydays_desc'] = 'Si la actividad sigue abandonada, recuerda a los administradores de nuevo pasados estos días.';
$string['stalerecurrence_subject'] = 'Recurrencia de Google Meet posiblemente abandonada: {$a->activity}';
$string['stalerecurrence_body'] = 'La actividad "{$a->activity}" del curso "{$a->course}" tiene aún {$a->futurecount} sesión(es) futura(s) programada(s), pero su última grabación fue el {$a->lastrecording}. Si sus clases en directo han terminado, cierra la recurrencia poniendo "Repetir hasta" en una fecha pasada aquí: {$a->editurl}';
$string['stalerecurrence_body_html'] = 'La actividad "<strong>{$a->activity}</strong>" del curso "<strong>{$a->course}</strong>" tiene aún <strong>{$a->futurecount}</strong> sesión(es) futura(s) programada(s), pero su última grabación fue el <strong>{$a->lastrecording}</strong>.<br>Si sus clases en directo han terminado, cierra la recurrencia poniendo <em>Repetir hasta</em> en una fecha pasada: <a href="{$a->editurl}">editar la actividad</a>.';
$string['stalerecurrence_editlink'] = 'Editar la actividad';
```

- [ ] **Step 3: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add lang/en/googlemeet.php lang/es/googlemeet.php
git commit -m "feat(googlemeet): lang strings for abandoned-recurrence alert"
```

---

### Task 3: Message provider

**Files:**
- Modify: `db/messages.php`

**Interfaces:**
- Produces: provider `stalerecurrence` bajo el componente `mod_googlemeet` (consumido por `message_send()` en Task 4).

- [ ] **Step 1: Añadir el provider**

En `db/messages.php`, dentro del array `$messageproviders`, añadir tras `recordingavailable`:

```php
    'stalerecurrence' => [
        'defaults' => [
            'popup' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'email' => MESSAGE_PERMITTED + MESSAGE_DEFAULT_ENABLED,
            'airnotifier' => MESSAGE_DISALLOWED,
        ],
    ],
```

- [ ] **Step 2: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add db/messages.php
git commit -m "feat(googlemeet): add stalerecurrence message provider"
```

---

### Task 4: Función de envío `googlemeet_send_stale_alert()`

**Files:**
- Modify: `locallib.php`
- Test: `tests/stale_recurrence_test.php`

**Interfaces:**
- Consumes: fila de `googlemeet_get_stale_recurrences()` (Task 1); provider `stalerecurrence` (Task 3); strings (Task 2).
- Produces: `googlemeet_send_stale_alert(stdClass $info): void` — envía una notificación por cada admin de `get_admins()`.

- [ ] **Step 1: Escribir el test que falla (con message sink)**

Añadir al test class:

```php
    public function test_send_stale_alert_notifies_admins(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();

        $info = (object) [
            'id' => 123,
            'name' => 'Live classes',
            'course' => 7,
            'coursename' => 'Test course',
            'cmid' => 456,
            'lastrecording' => 1795000000,
            'futurecount' => 3,
        ];

        googlemeet_send_stale_alert($info);

        $messages = $sink->get_messages();
        $this->assertCount(count(get_admins()), $messages);
        $this->assertEquals('mod_googlemeet', $messages[0]->component);
        $this->assertEquals('stalerecurrence', $messages[0]->eventtype);
        $this->assertStringContainsString('Live classes', $messages[0]->subject);
        $this->assertStringContainsString('update=456', $messages[0]->fullmessagehtml);
    }
```

- [ ] **Step 2: Ejecutar y ver que falla**

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php --filter test_send_stale_alert_notifies_admins`
Expected: FAIL con `Call to undefined function ...googlemeet_send_stale_alert()`.

- [ ] **Step 3: Implementar la función**

Añadir en `locallib.php`:

```php
/**
 * Send the abandoned-recurrence alert to every site admin.
 *
 * @param stdClass $info A row returned by googlemeet_get_stale_recurrences().
 * @return void
 */
function googlemeet_send_stale_alert(stdClass $info): void {
    $editurl = new moodle_url('/course/modedit.php', ['update' => $info->cmid]);
    $lastrec = $info->lastrecording
        ? userdate((int) $info->lastrecording, get_string('strftimedate', 'langconfig'))
        : '-';

    $a = (object) [
        'activity' => format_string($info->name),
        'course' => format_string($info->coursename),
        'lastrecording' => $lastrec,
        'futurecount' => (int) $info->futurecount,
        'editurl' => $editurl->out(false),
    ];

    $subject = get_string('stalerecurrence_subject', 'mod_googlemeet', $a);

    foreach (get_admins() as $admin) {
        $message = new \core\message\message();
        $message->component = 'mod_googlemeet';
        $message->name = 'stalerecurrence';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $admin;
        $message->subject = $subject;
        $message->fullmessage = get_string('stalerecurrence_body', 'mod_googlemeet', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = get_string('stalerecurrence_body_html', 'mod_googlemeet', $a);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = $editurl->out(false);
        $message->contexturlname = get_string('stalerecurrence_editlink', 'mod_googlemeet');
        message_send($message);
    }
}
```

- [ ] **Step 4: Ejecutar y ver que pasa**

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php --filter test_send_stale_alert_notifies_admins`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add locallib.php tests/stale_recurrence_test.php
git commit -m "feat(googlemeet): send abandoned-recurrence alert to site admins"
```

---

### Task 5: Ajustes en settings.php

**Files:**
- Modify: `settings.php`

**Interfaces:**
- Produces: configs `googlemeet/stalerecurrence_enabled` (bool, default 1), `googlemeet/stalerecurrence_weeks` (int, default 3), `googlemeet/stalerecurrence_renotifydays` (int, default 28). Consumidos por la tarea en Task 6.

- [ ] **Step 1: Añadir el heading + 3 ajustes**

En `settings.php`, dentro de `if ($ADMIN->fulltree) { ... }`, tras el bloque de `ytdlppath` (final del fichero):

```php
    // Abandoned-recurrence alerts.
    $settings->add(new admin_setting_heading(
        'googlemeet/stalerecurrence_heading',
        get_string('stalerecurrence_heading', 'googlemeet'),
        get_string('stalerecurrence_heading_desc', 'googlemeet')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'googlemeet/stalerecurrence_enabled',
        get_string('stalerecurrence_enabled', 'googlemeet'),
        get_string('stalerecurrence_enabled_desc', 'googlemeet'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'googlemeet/stalerecurrence_weeks',
        get_string('stalerecurrence_weeks', 'googlemeet'),
        get_string('stalerecurrence_weeks_desc', 'googlemeet'),
        3,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'googlemeet/stalerecurrence_renotifydays',
        get_string('stalerecurrence_renotifydays', 'googlemeet'),
        get_string('stalerecurrence_renotifydays_desc', 'googlemeet'),
        28,
        PARAM_INT
    ));
```

- [ ] **Step 2: Verificar sintaxis PHP**

Run: `php -l ~/desarrollo/moodle-mod_googlemeet/settings.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add settings.php
git commit -m "feat(googlemeet): settings for abandoned-recurrence alert"
```

---

### Task 6: Tarea programada + registro + anti-spam

**Files:**
- Create: `classes/task/check_stale_recurrence.php`
- Modify: `db/tasks.php`
- Test: `tests/stale_recurrence_test.php`

**Interfaces:**
- Consumes: `googlemeet_get_stale_recurrences()` (Task 1), `googlemeet_send_stale_alert()` (Task 4), configs (Task 5).
- Produces: clase `\mod_googlemeet\task\check_stale_recurrence` con `execute()`; estado anti-spam en `config_plugins` con nombre `stalealert_<gmid>` (plugin `googlemeet`).

- [ ] **Step 1: Escribir el test de orquestación (anti-spam + limpieza) que falla**

Añadir al test class:

```php
    /** Run the scheduled task, swallowing its mtrace output. */
    private function run_task(): void {
        $task = new \mod_googlemeet\task\check_stale_recurrence();
        ob_start();
        $task->execute();
        ob_end_clean();
    }

    public function test_task_notifies_once_then_respects_renotify(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('stalerecurrence_enabled', 1, 'googlemeet');
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        // First run: notifies once and records state.
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
        $this->assertNotEmpty(get_config('googlemeet', 'stalealert_' . $gm->id));
        $sink->close();

        // Second run (within renotify window): no new message.
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
        $sink->close();

        // Age the last-alert timestamp beyond the renotify window: notifies again.
        set_config('stalealert_' . $gm->id, time() - 40 * DAYSECS, 'googlemeet');
        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(count(get_admins()), $sink->get_messages());
        $sink->close();
    }

    public function test_task_clears_state_when_no_longer_stale(): void {
        global $DB;
        $this->resetAfterTest();
        $this->preventResetByRollback();

        set_config('stalerecurrence_enabled', 1, 'googlemeet');
        set_config('stalerecurrence_weeks', 3, 'googlemeet');
        set_config('stalerecurrence_renotifydays', 28, 'googlemeet');

        $gm = $this->make_module();
        $eventid = $DB->insert_record('googlemeet_events', (object) [
            'googlemeetid' => $gm->id, 'eventdate' => time() + DAYSECS,
            'duration' => 7200, 'timemodified' => time(), 'autosynced' => 0, 'syncattempts' => 0,
        ]);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $this->run_task();
        $this->assertNotEmpty(get_config('googlemeet', 'stalealert_' . $gm->id));

        // Remove the future session → no longer stale → state must be cleared.
        $DB->delete_records('googlemeet_events', ['id' => $eventid]);
        $this->run_task();
        $this->assertFalse(get_config('googlemeet', 'stalealert_' . $gm->id));
    }

    public function test_task_noop_when_disabled(): void {
        $this->resetAfterTest();
        $this->preventResetByRollback();
        set_config('stalerecurrence_enabled', 0, 'googlemeet');

        $gm = $this->make_module();
        $this->add_event($gm->id, time() + DAYSECS);
        $this->add_recording($gm->id, time() - 40 * DAYSECS);

        $sink = $this->redirectMessages();
        $this->run_task();
        $this->assertCount(0, $sink->get_messages());
    }
```

- [ ] **Step 2: Ejecutar y ver que falla**

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php --filter test_task_`
Expected: FAIL (clase `check_stale_recurrence` no existe).

- [ ] **Step 3: Crear la clase de tarea**

Crear `classes/task/check_stale_recurrence.php`:

```php
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Google Meet task - warn admins about abandoned recurrences.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_googlemeet\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/googlemeet/locallib.php');

/**
 * Weekly check that warns site admins when a googlemeet recurrence looks abandoned.
 */
class check_stale_recurrence extends \core\task\scheduled_task {

    /**
     * @return string
     */
    public function get_name() {
        return get_string('stalerecurrence_task', 'mod_googlemeet');
    }

    /**
     * Detect abandoned recurrences, notify admins (respecting the re-notify window),
     * and clear state for instances that recovered.
     */
    public function execute() {
        global $DB;

        if (!get_config('googlemeet', 'stalerecurrence_enabled')) {
            return;
        }

        $weeks = (int) get_config('googlemeet', 'stalerecurrence_weeks');
        if ($weeks <= 0) {
            $weeks = 3;
        }
        $renotifydays = (int) get_config('googlemeet', 'stalerecurrence_renotifydays');
        if ($renotifydays <= 0) {
            $renotifydays = 28;
        }

        $stale = googlemeet_get_stale_recurrences($weeks);

        $stillstale = [];
        $sent = 0;
        foreach ($stale as $info) {
            $stillstale[(int) $info->id] = true;
            $last = get_config('googlemeet', 'stalealert_' . $info->id);
            if ($last === false || (time() - (int) $last) > $renotifydays * DAYSECS) {
                googlemeet_send_stale_alert($info);
                set_config('stalealert_' . $info->id, time(), 'googlemeet');
                $sent++;
            }
        }

        // Re-arm: drop stored state for instances that are no longer stale.
        $like = $DB->sql_like('name', ':pattern');
        $records = $DB->get_records_select('config_plugins',
            "plugin = :plugin AND $like",
            ['plugin' => 'googlemeet', 'pattern' => 'stalealert_%'], '', 'id, name');
        foreach ($records as $rec) {
            $gmid = (int) substr($rec->name, strlen('stalealert_'));
            if (empty($stillstale[$gmid])) {
                unset_config('stalealert_' . $gmid, 'googlemeet');
            }
        }

        mtrace('mod_googlemeet stale recurrence check: ' . count($stale) . ' stale, ' . $sent . ' notified.');
    }
}
```

- [ ] **Step 4: Registrar la tarea (semanal, lunes 04:00)**

En `db/tasks.php`, añadir un elemento al array `$tasks`:

```php
    [
        'classname' => 'mod_googlemeet\task\check_stale_recurrence',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '4',
        'day' => '*',
        'dayofweek' => '1',
        'month' => '*'
    ],
```

- [ ] **Step 5: Ejecutar todos los tests del fichero**

Run: `cd ~/formacion51 && vendor/bin/phpunit public/mod/googlemeet/tests/stale_recurrence_test.php`
Expected: PASS (todos: detección 5 + envío 1 + tarea 3 = 9).

- [ ] **Step 6: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add classes/task/check_stale_recurrence.php db/tasks.php tests/stale_recurrence_test.php
git commit -m "feat(googlemeet): weekly scheduled task for abandoned-recurrence alerts"
```

---

### Task 7: README

**Files:**
- Modify: `README.md`

**Interfaces:** ninguna (documentación).

- [ ] **Step 1: Añadir sección al README**

Añadir una sección nueva en `README.md` (junto al resto de features/ajustes):

```markdown
### Abandoned-recurrence alerts

When a course's live classes end, its Google Meet activity can keep its recurrence active,
generating future "phantom" sessions that still show up in students' Moodle calendar. Because the
calendar sync is one-way (Moodle → Google Calendar, no reverse webhook), Moodle is the source of
truth for the schedule — closing the event in Google Calendar does **not** propagate back.

A weekly scheduled task (`\mod_googlemeet\task\check_stale_recurrence`, Mondays 04:00) flags any
activity that still schedules future sessions, has recorded before, but has had no recording for N
weeks, and notifies site admins with a link to edit the activity (set *Repeat until* to a past date).

Settings (Site administration → Plugins → Activity modules → Google Meet):

- **Enable abandoned-recurrence alerts** (`stalerecurrence_enabled`, default on)
- **Weeks without recording** (`stalerecurrence_weeks`, default 3)
- **Re-notify after (days)** (`stalerecurrence_renotifydays`, default 28)
```

- [ ] **Step 2: Commit**

```bash
cd ~/desarrollo/moodle-mod_googlemeet
git add README.md
git commit -m "docs(googlemeet): document abandoned-recurrence alerts"
```

---

### Task 8: Despliegue a producción (gated — ejecutar con OK del dueño)

**Files:** ninguno del repo (operación de deploy).

**Interfaces:** ninguna.

> El **bump de `version.php`** lo aplica el controlador de deploy (NO codex). Los cambios en `db/tasks.php` y `db/messages.php` solo se registran en la instalación viva tras un upgrade con versión superior.

- [ ] **Step 1: Sincronizar el código al webroot vivo**

```bash
rsync -a --exclude=.git --exclude=CLAUDE.md ~/desarrollo/moodle-mod_googlemeet/ ~/formacion51/public/mod/googlemeet/
```

- [ ] **Step 2: Aplicar upgrade (registra tarea + provider; el controlador bumpea version.php)**

```bash
cd ~/formacion51 && php admin/cli/upgrade.php --non-interactive
```
Expected: termina sin error; el ruido de scssphp es ignorable.

- [ ] **Step 3: Reset de opcache (obligatorio tras copiar .php)**

Ejecutar el script autoborrable dentro de `public/` vía HTTPS + `purge_caches.php` (según gotcha `opcache_fpm_deploy_gotcha_2026_06_25`).

- [ ] **Step 4: Verificar**

- Admin → Servidor → Tareas programadas: `check_stale_recurrence` visible, `faildelay=0`, nextrun un lunes 04:00.
- Admin → Plugins → Módulos → Google Meet: se ven los 3 ajustes nuevos.
- (Opcional) forzar una ejecución de prueba en un entorno seguro y confirmar que llega la notificación a admins.

---

## Self-Review

**1. Cobertura del spec:**
- Criterio (futuras + grabó antes + N semanas sin grabar) → Task 1 (+ tests negativos). ✓
- Ajustes `enabled`/`weeks`/`renotifydays` → Task 5. ✓
- Message provider a admins → Task 3 + Task 4. ✓
- Anti-spam + auto-limpieza (rearme) → Task 6 (2 tests). ✓
- Tarea semanal lunes 04:00 → Task 6 Step 4. ✓
- Tests (a-g del spec) → Tasks 1/4/6. ✓
- Deploy (rsync/upgrade/opcache) → Task 8. ✓
- README → Task 7. ✓

**2. Placeholders:** ninguno; todo el código está completo.

**3. Consistencia de tipos:** `googlemeet_get_stale_recurrences(int, ?int): array` y `googlemeet_send_stale_alert(stdClass): void` se usan idénticas en Task 6. Claves de string idénticas entre Task 2 (definición) y Tasks 4/5/6 (uso). Config keys `stalerecurrence_*` y `stalealert_<gmid>` consistentes entre Tasks 5 y 6.

## Notas de entorno (PHPUnit del box)
- Si el runner PHPUnit se invalida por bumps de otros plugins: reconstruir test DB con `util.php --drop` / `--install` (memoria `mod_googlemeet_hardening_2026_05_30`). Falta locale `en_AU.UTF-8` → `localedef -i en_AU -f UTF-8 ~/.locales/en_AU.UTF-8` + `export LOCPATH=~/.locales`.
- Ruta del binario PHPUnit / prefijo `public/mod/googlemeet` según cómo esté montado el runner en `~/formacion51`; ajustar el comando si difiere.
