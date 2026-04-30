# Quiz Battle Spec

## Goal

- Fast quiz battle service
- Anonymous participation only
- Users enter only a display name
- Question source is managed on a separate question server
- Battle runtime must use the local DB only

## User Flow

1. Room creator opens the battle creation screen.
2. An administrator syncs the latest category catalog into `qb_question_category` from the manager screen.
3. Room creator selects one or more themes from the local question catalog.
4. The system fetches the selected themes' questions from the question API and saves them locally.
5. The system creates a battle room and generates a share URL.
6. Players open the room URL and join by entering a display name.
7. The host starts the battle.
8. During the battle, questions are served only from the local DB cache.

## Question Source Policy

- The source of truth for questions is the remote question server.
- Theme identity is `cate1`, `cate2`, `id`.
- One theme contains all questions under that key.
- Individual questions are handled locally as `cate1`, `cate2`, `id`, `num`.
- The battle system imports and caches questions locally before battle start.
- The battle system does not call the remote question API during active gameplay.

## Current API Assumptions

### Category API

- `mode=c1`: returns large categories
- `mode=c2&cate1=...`: returns sub categories
- `mode=ids&cate1=...&cate2=...`: returns theme list

### Question API

- `mode=list&cate1=...&cate2=...&id=...`: returns all questions under the theme

### Question Fields

- `cate1`
- `cate2`
- `id`
- `num`
- `mondai`
- `qa`
- `qb`
- `qc`
- `qd`
- `kaito`
- `kaisetu`
- `url` may be absent

## Runtime Rules

- Players are anonymous and tracked by session plus local participant row.
- Names must be unique within the same battle room.
- The battle lineup is generated from locally cached questions only.
- Answer reveal must happen after the answer phase or by host action.
- Correct answer data should not be exposed to the client before reveal.

## Local Data Model

### Cached catalog

- `qb_question_category`
  - local cache of remote category and theme metadata
  - maintained by an authenticated manager screen

### Cached questions

- `qb_question_bank`
  - local cache of remote question rows
  - unique by `cate1`, `cate2`, `qid`, `qnum`

### Battle data

- `qb_group`
- `qb_battle`
- `qb_battle_scope`
- `qb_battle_participants`
- `qb_battle_lineup`
- `qb_battle_state`
- `qb_buzzes`

## Performance Policy

- Fetch remote catalog from the manager screen and cache it locally ahead of room creation.
- Fetch selected themes' questions before battle start and cache them locally.
- Never depend on the remote question server while a battle is in progress.
- Polling reduction and WebSocket migration remain a separate optimization step.

## Manager Operations

- Manager screen path: `server/public/manager/index.php`
- Manager login is required before running category sync
- Manager credentials are supplied by `.env`
- Recommended keys:
  - `QB_MANAGER_USER`
  - `QB_MANAGER_PASS`
  - or `QB_MANAGER_PASS_HASH`
- Battle creation screen reads category data only from local `qb_question_category`

## Open Items

- `url` handling when the source API does not provide it
- Whether explanation and source URL should be shown on the result page
- Whether room creators must select exactly one theme or may select multiple themes
- Whether a minimum question count greater than 3 should be enforced per room
