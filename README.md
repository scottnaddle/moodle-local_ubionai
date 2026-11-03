# local_ubionai

Moodle 4.5 LTS ~ 5.x local plugin that exposes a few course-authoring operations as web service
functions, for use by the UbionAI / moodle-agent-mcp server. No database tables; uninstalls cleanly.

* Component: `local_ubionai` — install to `local/ubionai` (Moodle 4.5, 5.0) or `public/local/ubionai` (Moodle 5.1+)
* Requires Moodle 4.5 LTS+ (2024100700); supported on Moodle 4.5 LTS ~ 5.3

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

### `local_ubionai_get_course_assignments`

`courseid`. Returns `{courses: [{id, fullname, shortname, assignments: [...]}], warnings}` with
the same top-level shape as `mod_assign_get_assignments`, so callers can merge the two. Each
assignment carries `id`, `cmid`, `course`, `name`, `visible`, `section`, `duedate`,
`allowsubmissionsfromdate`, `cutoffdate`, `gradingduedate`, the raw `grade` setting, `gradetype`
(`point` \| `scale` \| `none`), `grademax`, `scaleid`, `gradepass`, `markingworkflow`,
`markingallocation`, `teamsubmission`, `blindmarking`, `attemptreopenmethod` and `maxattempts`.

Unlike the core `mod_assign_get_assignments` function, which only returns courses the caller is
enrolled in, this function only requires `moodle/course:view` and `mod/assign:view` in the course
context. That lets the service user list assignments for courses it is not enrolled in (for
example to grade them). Requires both capabilities; hidden course modules are omitted.

## Setup

1. Copy this directory to `local/ubionai` (Moodle 4.5, 5.0) or `public/local/ubionai` (Moodle 5.1+), then run the upgrade.
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
  authorises the user and ensures exactly one usable permanent token. It reuses a valid tracked
  token and otherwise mints a new one; the result reports `token_action: reused|rotated|created`
  (`rotated` = minted while an untracked valid `ubionai-mcp` token exists, for example one written
  to a token file by the setup script). Such a token is never adopted, tracked or sent to the hub;
  it is left in place unless `--revoke-legacy` is given. Token row ids managed
  by the plugin are tracked in the `issued_token_ids` config (ids only; the token string is never
  stored). *Disconnect*
  deletes only those tracked tokens and leaves any other token of the service user in place,
  warning about the count on the status page and in the CLI. It keeps the user, role and service so
  reconnecting is cheap and idempotent; it also clears the stored hub registration data. Disconnect
  does **not** remove the tenant from the hub, so the hub administrator must delete it before
  registering the same site again (otherwise the hub returns `already_registered` / HTTP 409).
* **CLI** — from the Moodle dirroot:

  ```
  php local/ubionai/cli/connection.php --status
  php local/ubionai/cli/connection.php --connect [--iprestriction=203.0.113.10] [--revoke-legacy]
  php local/ubionai/cli/connection.php --connect --regcode-file=/path/to/code
  php local/ubionai/cli/connection.php --connect --regcode-file=-
  php local/ubionai/cli/connection.php --connect --regcode=VALUE
  php local/ubionai/cli/connection.php --disconnect
  ```

  `--regcode-file=PATH` reads the first line of a regular file (at most 1 KiB) and warns when the
  file is group- or world-readable. `--regcode-file=-` reads the same from stdin. `--regcode=VALUE`
  is accepted but visible in the process list and shell history. Use only one of the two options,
  and only with `--connect`. Registration failure after successful provisioning exits with code 3;
  the registration code itself is never printed. `--revoke-legacy` (only with `--connect`) deletes
  the untracked `ubionai-mcp` tokens of `svc_ubionai` on service `ubionai`, expired or not; tokens
  with another name, user or service are never touched.

The web service token is never returned by any API, printed, echoed, logged or written to plugin
config. Only token metadata (count, `timecreated`, `lastaccess`) is observable. Moodle core itself
stores `external_tokens.token` in plain text; that is outside this plugin's control.

### Upgrading to 0.5.1

No data change. Connect no longer adopts an untracked `ubionai-mcp` token: such a token's string
already exists outside Moodle (the setup script writes it to `--token-file`), so handing it to the
hub would put one secret in three places. Connect now mints a separate token for the hub
(`token_action: rotated`) and leaves the legacy token in place, untracked; *Disconnect* does not
delete it. Run `php local/ubionai/cli/connection.php --connect --revoke-legacy` once nothing else
uses it.

If 0.5.0 reported `token_action: adopted`, that token is still tracked and is the one the hub holds;
it cannot be told apart from a token the plugin minted. To separate them, re-issue the setup
script's token file if it is still needed, then *Disconnect*, *Connect* and register with the hub
again (the hub administrator must first delete the existing tenant).

`local_ubionai_list_course_assignments` now returns `total: null` unless the caller has
`moodle/course:view` in the system context (the provisioned service role has it), so that a
restricted caller does not learn how many courses site-wide contain assignments. Such callers page
until a page returns fewer `courses` + `warnings` than `limit`.

### Upgrading to 0.5.0

(Historical: token adoption described below was removed in 0.5.1.)

After upgrading, click *Connect* (or run `php local/ubionai/cli/connection.php --connect`) once. No
new token is created: an existing valid token is reused, or an existing valid token named
`ubionai-mcp` for service `ubionai` and user `svc_ubionai` is adopted (reported as
`token_action: adopted`). The new `local_ubionai_list_course_assignments` function is attached to
the service during the same Connect run, because Moodle registers new external functions after the
upgrade hook has run. Re-register with the hub after reconnecting if the hub needs the new token.

Adoption rules: a token is adopted only when it is PERMANENT, unexpired, belongs to service
`ubionai` and user `svc_ubionai`, and is named exactly `ubionai-mcp`. Tokens with any other name or
on another service are never reused, adopted or deleted; they are counted as untracked. Adopted
tokens are tracked, so *Disconnect* revokes them. Disconnect semantics are otherwise unchanged.
Residual risk: if an administrator manually created a token on service `ubionai` for user
`svc_ubionai` named exactly `ubionai-mcp`, Connect will adopt it and Disconnect will revoke it.

`--iprestriction` accepts a comma-separated list of IPv4/IPv6 addresses or strict CIDR networks.
Values are trimmed, canonicalised and deduplicated before being stored (maximum 255 characters);
invalid values, host bits in a CIDR, zone ids and hostnames exit with code 2. The value requires
`--connect`.

## Teacher OAuth connections (Easy Connect spike, hub-staging trial)

Teachers connect Claude with their own Moodle login; the hub then calls Moodle with a token of that
teacher, limited to the `ubionai_user` service. Endpoints:

| Path | Who | What |
|---|---|---|
| `oauth/authorize.php?req=…` | teacher's browser | verifies the hub's signed request, `require_login`, refuses site administrators and users without `local/ubionai:connect` in any course (editing and non-editing teachers by default), shows a consent page (user, site, client, hub, scope), then redirects to `{hub_url}/t/{hub_site_id}/oauth/callback?code=…&state=…` (one-time code, 2 minutes) or `?error=access_denied` |
| `oauth/exchange.php` (POST `assertion`) | hub back channel | verifies the signed assertion, consumes the code, assigns the role `ubionai_wsuser` (only `webservice/rest:use`) at system level, authorises the user on `ubionai_user` and mints a token (30 days, name `ubionai-user`); returns `{userid, tokenid, wstoken, validuntil}` |
| `oauth/revoke.php` (POST `assertion`) | hub back channel | deletes one `ubionai_user` token of that user |
| `connections.php` | the user (profile > Claude connections) | lists the user's `ubionai_user` tokens, revoke |

Tokens are only ever minted or revoked for the `ubionai_user` service (`moodle_mobile_app` and other
services are refused in code) and never for site administrators. When a user's last connection is
revoked, the service authorisation and the role are removed.

Settings (all three required; values come from the hub operator):

- `local_ubionai/hub_url` — hub public URL, e.g. `https://hub-staging.ubion.global`
- `local_ubionai/hub_site_id` — the `<site>` of `https://hub/t/<site>/mcp`
- `local_ubionai/oauth_secret` — shared secret, the same value as the hub file
  `secrets/tenants/<site>/oauth.secret` (at least 32 characters). Messages are
  `base64url(json) . "." . base64url(HMAC-SHA256(secret, "ubionai-oauth-v1." . body))` with claims
  `typ`, `aud` (= `$CFG->wwwroot`), `site` and `exp`.

## Hub self-registration: decisions of 2026-11-03 (contract §8.3)

* **Default hub.** An empty `local_ubionai/hub_url` uses `https://mcp.ubion.global`
  (`registrar::DEFAULT_HUB`). Administrators can set another hub URL.
* **New sites** are created by the hub as `active` at the lowest tier (`limited`). The hub operator
  can turn auto-activation off (the site then shows "pending") or disable a single site (the plugin
  then reports `host_blocked`).
* **Limits** come from hub configuration (provisional: 5 teachers, 200 requests per day). The plugin
  shows `tenant.limits` exactly as the hub reports them (`max_teachers`, `daily_requests`,
  `concurrency`, `llm_proxy`); it does not enforce them.
* **Re-registration** always sends `site_identifier_hash`. The hub keeps teacher connections only
  when it matches the recorded value; its `grants_kept` answer is shown on the connection page and
  in `cli/register.php --status`.
* **Operator-managed host**: `409 site_managed_by_operator`; the operator links the site by hand.
* **Secrets**: HKDF / hash design unchanged. `tests/registration/secret_logging_test.php` checks that
  no form of `site_secret` or the derived MAC key reaches output/mtrace, debugging, `error_log`,
  log events or the config log.

## Privacy

The plugin itself stores no personal data in Moodle and has no database tables or user preferences.
It sends data outbound to the UBION AI hub and its LLM sub-processors (full name, email, grades,
submissions, forum authors/posts, course content and staff prompts) so staff requests can be
answered. The privacy provider declares this external location; export and deletion requests are
no-ops until hub-side handling ships in a later iteration.

## Installation

Clone or copy this repository into `<moodleroot>/local/ubionai` (Moodle 4.5 LTS, 5.0) or
`<moodleroot>/public/local/ubionai` (Moodle 5.1 and later; `requires = 2024100700`, supported on
Moodle 4.5 LTS ~ 5.3), then run the upgrade (`php admin/cli/upgrade.php`).

## History

### 0.6.1-spike4-wp0 (2026110204)

* Moodle compatibility: the plugin now supports Moodle 4.5 LTS through Moodle 5.3
  (`requires = 2024100700`, `supported = [405, 503]`). Previously it required Moodle 5.3 LTS.
* PHPUnit data providers now also carry `@dataProvider` docblock annotations next to the existing
  `#[DataProvider]` attributes, so the test suite runs on Moodle 4.5 (PHPUnit 9.6) as well as on
  Moodle 5.x (PHPUnit 11).
* The service user is created with the API the running core provides: `user_create_user()` on
  Moodle 4.5, `\core\user::create_user()` from Moodle 5.3 (where the old function is deprecated).
* CI now tests Moodle 4.5 (PHP 8.1 and 8.3), 5.0 (8.2), 5.1 (8.3), 5.2 (8.4) and 5.3 (8.4) on PostgreSQL.

### 0.5.1 (2026103106)

* Security review 2026-11-01, item 4b: Connect never adopts an untracked `ubionai-mcp` token; it
  mints a new token for the hub (`token_action: rotated`) and leaves the legacy one untracked.
  New opt-in `--revoke-legacy` CLI flag deletes such tokens. `provision()` reports
  `legacy_revoked` and `legacy_untracked`; `token_created` is true for `created` and `rotated`.
* Item 4c: the Disconnect confirmation, the Connect notifications and the CLI usage now describe
  which tokens are deleted and which are kept.
* Item 3c: `local_ubionai_list_course_assignments` returns `total: null` to callers without
  `moodle/course:view` in the system context.

### 0.5.0 (2026103105)

* Connect is idempotent: it reuses a valid tracked token, adopts a valid untracked token named
  `ubionai-mcp`, and otherwise mints one. The result and the `site_connected` event report
  `token_action: reused|adopted|created`; `token_created` remains for backward compatibility.
  Tracked tokens are filtered by service, user, type and expiry, so an expired or foreign tracked
  row is never reused. Disconnect semantics are unchanged, including revocation of adopted tokens.
* New `local_ubionai_list_course_assignments` read function: a paged (`offset`, `limit` 1..100),
  `ORDER BY c.id` list of non-site courses that contain assignments, with per-course warnings and
  no enrolment requirement.
* Hub: `list_assignments` pages through all courses with assignments when `course_ids` is omitted,
  tags plugin-sourced courses with `source: local_ubionai`, returns a `paging` block and falls back
  sequentially for plugins without the new function.
* Hub pseudonymisation: title fields (`name`, `sectionname`, `modname`, `categoryname`,
  `coursename`, `groupname`, `displayname`) skip heuristic Korean-name detection while seeded known
  names are still masked; `idnumber` is no longer a user marker, so course names in `list_courses`
  are readable while user records (which carry `firstname`/`lastname`/`email`/`username`/`userid`)
  stay masked.

### 0.4.0 (2026103104)

* New `local_ubionai_get_course_assignments` read function, plus `moodle/course:view` and
  `mod/assign:view` for the service role. It lists assignments for courses the service user is not
  enrolled in, unlike the enrolment-based `mod_assign_get_assignments`.
* `--iprestriction` and the hub `egress_ip` are validated and normalised (strict IPv4/IPv6 and
  CIDR list, at most 255 characters). Invalid hub values are rejected before any config is written;
  invalid CLI values exit with code 2.
* Issued token row ids are tracked in the `issued_token_ids` config so disconnect revokes only the
  tokens this plugin issued. Untracked tokens are left in place and reported on the status page,
  in the CLI and in the disconnect event. The upgrade does not adopt existing tokens.

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
