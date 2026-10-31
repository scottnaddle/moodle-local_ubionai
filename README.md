# local_grokbot

Moodle 5.x local plugin that exposes a few course-authoring operations as web service
functions, for use by the Grokbot / moodle-mcp server. No database tables; uninstalls cleanly.

* Component: `local_grokbot` — install to `public/local/grokbot`
* Requires Moodle 5.1+ (2025100600)

## Functions

All functions validate parameters, validate the context and check capabilities. Settings and
questions are passed as JSON strings; invalid JSON raises `invalid_parameter_exception`.

### `local_grokbot_create_module`

| param | type | default |
|---|---|---|
| courseid | int | |
| section | int (section number) | |
| modname | `assign` \| `quiz` \| `forum` \| `page` \| `resource` \| `url` | |
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

### `local_grokbot_update_module`

`cmid`, `name` (`''` = unchanged), `intro` (`''` = unchanged), `visible` (`-1` = unchanged),
`settings` (JSON object of fields to change). Returns `{cmid, updated}`.
Requires `moodle/course:manageactivities`. The update starts from the current module data
(`get_moduleinfo_data()`), so anything not in `settings` keeps its value: assign plugin settings, quiz
review options, quiz overall feedback, quiz access-rule settings, page/url/resource display options,
url parameters, page content and resource files (pass `files` to replace them), the current
`availability` and completion settings. The same `completion*` and `availability` keys as for
create are accepted; changing completion resets the activity's completion state. For quizzes, `grade` changes the
maximum grade through the grade calculator.

### `local_grokbot_add_quiz_questions`

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

Questions are saved in a `grokbot` category under the quiz's own question bank and appended to the
quiz. Returns `{questionids, sumgrades}`. Requires `mod/quiz:manage` and `moodle/question:add`.
Rejected if the quiz already has attempts.

### `local_grokbot_update_section`

`courseid`, `section` (number), `name` (null = unchanged, `''` = default name), `summary`
(HTML, null = unchanged). Returns `{sectionid, updated}`. Names longer than 255 characters and
unknown section numbers raise `invalid_parameter_exception`. Requires `moodle/course:update`.

### `local_grokbot_add_sections`

`courseid`, `names` (list; `''` = default name), `summaries` (optional list of HTML, matched to
`names` by position). Appends the sections to the end of the course (at most 50 per call).
Returns `{sections: [{id, section}]}`. Requires `moodle/course:update`.

### `local_grokbot_set_section_availability`

`courseid`, `section` (number), `availability` (core_availability JSON tree; `''` = no restrictions).
Returns `{sectionid, availability}`. Requires `moodle/course:update`.

## Setup

1. Copy this directory to `public/local/grokbot` and run the upgrade.
2. Add the functions to the external service used by the bot's token.
