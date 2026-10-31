# local_grokbot

Moodle 5.x local plugin that exposes a few course-authoring operations as web service
functions, for use by the Grokbot / moodle-mcp server. No database tables; uninstalls cleanly.

* Component: `local_grokbot` — install to `public/local/grokbot`
* Requires Moodle 5.3 LTS+ (2026100500)

## Functions

All functions validate parameters, validate the context and check capabilities. Settings and
questions are passed as JSON strings; invalid JSON raises `invalid_parameter_exception`.

### `local_grokbot_create_module`

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

### `local_grokbot_set_rubric`

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

### `local_grokbot_set_grade_categories`

`courseid`, `categories` (JSON `[{"name": "...", "weight": 25, "cmids": [12, {"cmid": 13, "itemnumber": 1}]}]`),
`aggregation` (`weighted_mean` \| `natural` \| `mean`, default `weighted_mean`). Sets the course category's
aggregation, then creates (or reuses, by name) categories directly below it and moves the listed activities'
grade items into them. A plain cmid moves all its grade items; `{cmid, itemnumber}` moves one (workshop: 0 =
submission, 1 = assessment). `weight` is the category weight (`weighted_mean`: coefficient; `natural`: percent,
stored as an overridden weight; ignored for `mean`). New categories use the same aggregation. Returns
`{coursecategoryid, aggregation, categories: [{name, categoryid, gradeitemid, weight, created, itemids}],
uncategorised}` where `uncategorised` counts grade items still directly in the course category.
Requires `moodle/grade:manage`.

### `local_grokbot_create_badge`

`courseid`, `name`, `description`, `criteria` (JSON `{"activities": [cmid, ...], "min_course_grade": 70}`),
`enable` (default false). Creates a course badge (issuer: site defaults) with an "all criteria" overall rule,
an activity completion criterion (all listed activities; they need completion tracking) and/or a course
completion criterion with an optional minimum grade (needs course completion to be configured). The image is
drawn with GD (fallback `pix/badge.png`). `enable` activates the badge and awards it to users who already
qualify. Returns `{badgeid, status, awards, warnings}`. Requires badges and course badges to be enabled, and
`moodle/badges:createbadge` + `moodle/badges:configurecriteria`.

## Setup

1. Copy this directory to `public/local/grokbot` and run the upgrade.
2. Add the functions to the external service used by the bot's token.
