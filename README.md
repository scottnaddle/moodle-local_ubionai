# local_ubionai

Moodle 5.x local plugin that exposes a few course-authoring operations as web service
functions, for use by the UbionAI / moodle-agent-mcp server. No database tables; uninstalls cleanly.

* Component: `local_ubionai` — install to `public/local/ubionai`
* Requires Moodle 5.3 LTS+ (2026100500)

## Functions

All functions validate parameters, validate the context and check capabilities. Settings and
questions are passed as JSON strings; invalid JSON raises `invalid_parameter_exception`.

### `local_ubionai_create_module`

| param | type | default |
|---|---|---|
| courseid | int | |
| section | int (section number) | |
| modname | `assign` \| `quiz` \| `forum` \| `page` \| `resource` \| `url` \| `workshop` \| `wiki` \| `glossary` \| `feedback` \| `h5pactivity` \| `openmaic` | |
| name | string | |
| intro | HTML | `''` |
| visible | int | `1` |
| settings | JSON object | `{}` |

Returns `{cmid, instance, modname}`. Requires `moodle/course:manageactivities` and
`mod/<modname>:addinstance` in the course context.

A complete moduleinfo is built from defaults (taken from the core test generators / mod_form
defaults) and then overlaid with `settings`. Useful keys:

* assign: `duedate`, `cutoffdate`, `allowsubmissionsfromdate`, `gradingduedate`, `grade`,
  `assignsubmission_onlinetext_enabled`, `assignsubmission_file_enabled`,
  `assignsubmission_file_maxfiles`, `assignsubmission_file_maxsizebytes`, `completion`, `completionsubmit`
* quiz: `timeopen`, `timeclose`, `timelimit`, `attempts`, `grademethod`, `grade`, `questionsperpage`,
  `preferredbehaviour`, review options such as `correctnessimmediately`, `marksclosed`, `rightansweropen`
  (`<attempt|correctness|maxmarks|marks|specificfeedback|generalfeedback|rightanswer|overallfeedback>`
  + `<during|immediately|open|closed>`)
* forum: `type`, `assessed`, `scale` (positive = max rating points; enables ratings), `grade_forum`
  (whole-forum grading max points), `duedate`, `cutoffdate`, `completion`, `completionposts`,
  `completiondiscussions`, `completionreplies`
* page: `content` (HTML), `display`, `printintro`, `printlastmodified`, `popupwidth`, `popupheight`
* resource: `files` (**required** on create: draft item id of a file uploaded to the calling user's
  draft area, e.g. with `core_files_upload`), `display`, `showsize`, `showtype`, `showdate`, `printintro`
* url: `externalurl` (**required**), `display`, `printintro`
* workshop: `strategy` (`accumulative` \| `comments` \| `numerrors` \| `rubric`), `grade` (submission max,
  default 80), `gradinggrade` (assessment max, default 20), `submissiontypetext` / `submissiontypefile`
  (0 disabled, 1 available, 2 required), `nattachments`, `useselfassessment`, `submissionstart`,
  `submissionend`, `assessmentstart`, `assessmentend`, `phaseswitchassessment`, `instructauthors`,
  `instructreviewers`, `conclusion` (HTML), `submissiongradepass`, `gradinggradepass`. The workshop is
  created in the setup phase; both grade items go to the course grade category unless `gradecategory` /
  `gradinggradecategory` are given.
* wiki: `wikimode` (`collaborative` \| `individual`), `firstpagetitle` (defaults to the activity name),
  `defaultformat` (`html` \| `creole` \| `nwiki`), `forceformat`
* glossary: `displayformat` (default `dictionary`), `defaultapproval`, `allowcomments`, `allowduplicatedentries`,
  `usedynalink`, `entbypage`, `completionentries`
* feedback: `anonymous` (1 anonymous, 2 with names), `multiple_submit`, `email_notification`, `autonumbering`,
  `timeopen`, `timeclose`, `page_after_submit` (HTML), `completionsubmit`. Questions are added in Moodle.
* h5pactivity: `packagefile` (**required** on create: draft item id holding exactly one `.h5p` file),
  `grade`, `grademethod`, `enabletracking`, `reviewmode`, `displayoptions`; completion with
  `completionusegrade` / `completionpassgrade` + `gradepass`
* openmaic: `manuscript` (lecture manuscript / prompt), `lectureid` (link an existing OpenMAIC lecture; the
  activity is then `linked`, otherwise `draft`), `grade` (default 100), `completionminprogress`
  (1-100 % watched; implies automatic completion). Generation is started from the activity page.

`display` uses the `RESOURCELIB_DISPLAY_*` values: 0 auto, 1 embed, 2 frame, 3 new window, 4 force
download, 5 open, 6 popup. Defaults come from the module's site settings.

Common keys for every module type:

* `completion`: 0 none, 1 manual, 2 automatic. Setting any completion rule (`completionview`,
  `completionsubmit`, `completionusegrade`, `completionpassgrade`, `completionposts`, ...) without
  `completion` turns on automatic completion; setting `completion` to 0 or 1 clears the rules.
  `completionpassgrade` also needs `completionusegrade` and a passing grade (`gradepass`).
  Completion settings are ignored when completion tracking is disabled for the course.
* `availability`: core_availability tree as a JSON string (or a nested object), e.g.
  `{"op":"&","c":[{"type":"completion","cm":12,"e":1}],"showc":[true]}`. `""`/`null` removes all
  restrictions. The tree is validated; invalid trees raise `invalid_parameter_exception`.

### `local_ubionai_update_module`

`cmid`, `name` (`''` = unchanged), `intro` (`''` = unchanged), `visible` (`-1` = unchanged),
`settings` (JSON object of fields to change). Returns `{cmid, updated}`.
Requires `moodle/course:manageactivities`. The update starts from the current module data
(`get_moduleinfo_data()`), so anything not in `settings` keeps its value: assign plugin settings, quiz
review options, quiz overall feedback, quiz access-rule settings, page/url/resource display options,
url parameters, page content and resource files (pass `files` to replace them), the current
`availability` and completion settings. The same `completion*` and `availability` keys as for
create are accepted; changing completion resets the activity's completion state. For quizzes, `grade` changes the
maximum grade through the grade calculator.

### `local_ubionai_add_quiz_questions`

`quizid` (quiz instance id) and `questions`, a JSON array of:

```json
{
  "type": "multichoice|truefalse|shortanswer|essay",
  "name": "optional", "text": "<p>HTML</p>", "mark": 1, "generalfeedback": "",
  "single": true,
  "answers": [{"text": "...", "fraction": 1, "feedback": ""}],
  "correct": true, "feedbacktrue": "", "feedbackfalse": "",
  "usecase": false,
  "graderinfo": "", "responseformat": "editor", "responsefieldlines": 10
}
```

Questions are saved in a `ubionai` category under the quiz's own question bank and appended to the
quiz. Returns `{questionids, sumgrades}`. Requires `mod/quiz:manage` and `moodle/question:add`.
Rejected if the quiz already has attempts.

### `local_ubionai_update_section`

`courseid`, `section` (number), `name` (null = unchanged, `''` = default name), `summary`
(HTML, null = unchanged). Returns `{sectionid, updated}`. Names longer than 255 characters and
unknown section numbers raise `invalid_parameter_exception`. Requires `moodle/course:update`.

### `local_ubionai_add_sections`

`courseid`, `names` (list; `''` = default name), `summaries` (optional list of HTML, matched to
`names` by position). Appends the sections to the end of the course (at most 50 per call).
Returns `{sections: [{id, section}]}`. Requires `moodle/course:update`.

### `local_ubionai_set_section_availability`

`courseid`, `section` (number), `availability` (core_availability JSON tree; `''` = no restrictions).
Returns `{sectionid, availability}`. Requires `moodle/course:update`.

### `local_ubionai_set_rubric`

`cmid`, `name`, `criteria` (JSON `[{"description": "...", "levels": [{"definition": "...", "score": 0}]}]`),
`description` (HTML, `''`), `areaname` (`submissions`). Each criterion needs a description and at least two
levels with distinct scores >= 0. Returns `{definitionid, method, criteria, maxscore}`.

* Activities with advanced grading (assign `submissions`, forum `forum`, ...): sets the area's active method to
  rubric and saves a ready rubric definition, replacing the current criteria. The rubric is shown to students
  in advance, with level scores. Rejected once the rubric has been used for grading. Requires
  `moodle/grade:managegradingforms`.
* Workshops (`strategy` must be `rubric`): replaces the assessment form (grid layout); scores must be whole
  numbers 0-100. Rejected once assessments have been graded. `definitionid` is 0. Requires
  `mod/workshop:editdimensions`.

### `local_ubionai_set_grade_categories`

`courseid`, `categories` (JSON `[{"name": "...", "weight": 25, "cmids": [12, {"cmid": 13, "itemnumber": 1}]}]`),
`aggregation` (`weighted_mean` \| `natural` \| `mean`, default `weighted_mean`). Sets the course category's
aggregation, then creates (or reuses, by name) categories directly below it and moves the listed activities'
grade items into them. A plain cmid moves all its grade items; `{cmid, itemnumber}` moves one (workshop: 0 =
submission, 1 = assessment). `weight` is the category weight (`weighted_mean`: coefficient; `natural`: percent,
stored as an overridden weight; ignored for `mean`). New categories use the same aggregation. Returns
`{coursecategoryid, aggregation, categories: [{name, categoryid, gradeitemid, weight, created, itemids}],
uncategorised}` where `uncategorised` counts grade items still directly in the course category.
Requires `moodle/grade:manage`.

### `local_ubionai_create_badge`

`courseid`, `name`, `description`, `criteria` (JSON `{"activities": [cmid, ...], "min_course_grade": 70}`),
`enable` (default false). Creates a course badge (issuer: site defaults) with an "all criteria" overall rule,
an activity completion criterion (all listed activities; they need completion tracking) and/or a course
completion criterion with an optional minimum grade (needs course completion to be configured). The image is
drawn with GD (fallback `pix/badge.png`). `enable` activates the badge and awards it to users who already
qualify. Returns `{badgeid, status, awards, warnings}`. Requires badges and course badges to be enabled, and
`moodle/badges:createbadge` + `moodle/badges:configurecriteria`.

## Setup

1. Copy this directory to `public/local/ubionai` and run the upgrade.
2. Add the functions to the external service used by the bot's token.

## Connection

The plugin can provision its own Moodle web service connection.

* **Settings** — Site administration > Plugins > Local plugins > UBION AI connection. The optional
  `Hub URL` must start with `https://`; leave it empty to provision the web service locally without
  registering with a hub.
* **Registration** — the hub administrator issues a one-time registration code for this site
  (`moodle-hub-admin issue-code --site-id X --moodle-url <wwwroot> --out FILE`). With a hub URL
  configured and the site not yet registered, the status page replaces *Connect* with a form
  containing a registration code field. Submitting the code provisions the local service and calls the hub
  `POST /register` endpoint. The code is used once and is never stored, logged, echoed or placed in
  a URL. On success the page shows the hub site ID, MCP endpoint, registration time and hub egress
  IP. The MCP bearer returned by the hub is stored encrypted and is not displayed.
* **Status page** — `/local/ubionai/connect.php` shows the connection state and has *Connect* and
  *Disconnect* buttons. *Connect* enables web services and REST, creates the `svc_ubionai` service
  user, the `ubionai_service` role, the restricted `ubionai` external service with its functions,
  authorises the user and issues one permanent token. *Disconnect* deletes the token(s) but keeps the
  user, role and service so reconnecting is cheap and idempotent; it also clears the stored hub
  registration data. Disconnect does **not** remove the tenant from the hub, so the hub
  administrator must delete it before registering the same site again (otherwise the hub returns
  `already_registered` / HTTP 409).
* **CLI** — from the Moodle dirroot:

  ```
  php local/ubionai/cli/connection.php --status
  php local/ubionai/cli/connection.php --connect [--iprestriction=203.0.113.10]
  php local/ubionai/cli/connection.php --connect --regcode-file=/path/to/code
  php local/ubionai/cli/connection.php --connect --regcode-file=-
  php local/ubionai/cli/connection.php --connect --regcode=VALUE
  php local/ubionai/cli/connection.php --disconnect
  ```

  `--regcode-file=PATH` reads the first line of a regular file (at most 1 KiB) and warns when the
  file is group- or world-readable. `--regcode-file=-` reads the same from stdin. `--regcode=VALUE`
  is accepted but visible in the process list and shell history. Use only one of the two options,
  and only with `--connect`. Registration failure after successful provisioning exits with code 3;
  the registration code itself is never printed.

The web service token is never returned by any API, printed, echoed, logged or written to plugin
config. Only token metadata (count, `timecreated`, `lastaccess`) is observable. Moodle core itself
stores `external_tokens.token` in plain text; that is outside this plugin's control.

## Privacy

The plugin itself stores no personal data in Moodle and has no database tables or user preferences.
It sends data outbound to the UBION AI hub and its LLM sub-processors (full name, email, grades,
submissions, forum authors/posts, course content and staff prompts) so staff requests can be
answered. The privacy provider declares this external location; export and deletion requests are
no-ops until hub-side handling ships in a later iteration.

## Installation

Clone or copy this repository into `<moodleroot>/local/ubionai` (Moodle 5.3 LTS or later, `requires = 2026100500`),
then run the upgrade (`php admin/cli/upgrade.php`).

## History

### 0.3.0 (2026103103)

* Hub registration against `POST /register` with a one-time registration code, from the connection
  page or the CLI (`--regcode-file`, `--regcode-file=-`, `--regcode`).
* Hub URLs are restricted to https (no credentials, query or fragment); registration responses are
  validated before any config is written.
* On success the hub site ID, MCP endpoint, egress IP and registration time are stored; the MCP
  bearer is stored encrypted and never displayed. Disconnect clears the hub registration data.
* The registration code is never stored, logged or printed.

### 0.2.0 (2026103101)

* Full privacy provider describing the external UBION AI hub location.
* One-click connection: admin status page, settings, CLI tool, idempotent provisioner and
  connect/disconnect events.
* The quiz question category is now named `ubionai`; the upgrade renames existing top-level
  `grokbot` categories in quiz contexts (skipping contexts that already have an `ubionai` sibling).
* The setup script requires `--token-file` unless `--no-token` is passed, hardens token file
  permissions and never prints the token.

### 0.1.0 (2026103100)

This plugin was previously developed as `local_grokbot` inside the `moodle-agent-mcp` repository and was
split out (with history) and renamed to `local_ubionai`. It is a new component: version restarts at
2026103100 (release 0.1.0). Uninstall `local_grokbot` before or after installing this plugin; there are no
database tables to migrate.

## License

GNU General Public License v3.0 or later (GPL-3.0-or-later). See `LICENSE`.
