# Draft System — Product Context

## Overview

The application is a draft/selection platform.

A draft is a session created by a user where a set of participants select from a collection of available items in a defined order.

The initial use case is location/map selection, but the system is intended to support broader use cases such as bidding, allocation, yard sales, and other scenarios where participants take turns selecting from available items.

The core concept is:

> Participants are assigned a selection order and take turns selecting from a collection of available items. Once an item is selected, it is no longer available for subsequent selections.

The selectable items do not necessarily have to be geographical locations. The system should remain flexible enough to support different types of draftable items.

## Draft Creation

A draft can be created by a user through the **Create Draft** action.

The person who creates the draft is the draft creator/owner and is responsible for configuring the draft.

The owner is also the **host**: nobody can select anything until the host starts the draft, and only the host can start it. The owner and the participants can see the draft's details; only the owner can see the participants' emails and invitation links.

The host can open the picking page to watch the draft live even if they are not also a participant, but only participants can pick. The home page lists every session a user hosts or takes part in, so the host can always get back to one to start or watch it.

The creator currently:

* Defines the participants.
* Creates/configures the interests, maps, or other selectable content required by the draft.
* Defines the draft rules.
* Sets the number of participants.
* Sets the selection timer.

The exact implementation of these features should be determined by inspecting the existing codebase rather than assumed from this document.

### Editing a Session

Until the host starts the draft, they can change any of it from the session's edit page:

* the settings: name, description, turn timer and scheduled start,
* the items: add, rename, replace or remove photos, remove,
* the participants: invite more people, remove people, and
* the selection order.

The number of participants and items entered when the session was created is only a starting point. The real counts are what is shown.

Once the host starts the draft, all of this is fixed. That is enforced on the server when each change is saved, so an edit and the host pressing Start can never both take effect.

Edits are announced live. Anyone already on the picking page has their page reloaded so they see the current items and players. A participant who is removed is not told; they simply lose access to the session.

## Participants

Participants are invited by **email address**. The owner shares an invitation link with each participant.

People who do not have an account can register first and then join with the link. An invitation only works for an account with the invited email address, and an email can be invited once per draft.

Users are identified by their **username**. The `users` table has no separate "name" field.

## Selection Order

The draft creator assigns a selection order to participants.

For example:

1. Participant A
2. Participant B
3. Participant C
4. Participant D

Participants select in that order.

After a participant selects an item, that item becomes unavailable for subsequent participants.

The selection mechanism is a core part of the system and should remain independent of the specific type of item being selected.

## Current Draft Rules

The current draft configuration includes at least:

* Number of participants
* Start date and time
* Selection timer (seconds per turn)

The start time is entered in the creator's local time and stored in UTC.

Additional rules may be introduced as the product evolves.

When implementing new rules, avoid unnecessarily coupling them to a specific draft use case.

## Product Direction

The original concept was designed around selecting locations from a map.

The broader goal is to provide a reusable turn-based selection system.

Potential use cases include:

* Map/location drafts
* Bidding or allocation
* Yard-sale style selection
* Other scenarios where participants select items in sequence

The underlying domain model should therefore distinguish between:

* The draft/session
* Participants
* Selection order
* Selectable items
* The act/result of making a selection
* Rules governing the selection process

The implementation should not assume that every draft consists of map locations.

## Working With the Existing Codebase

The codebase already contains implementations of many of the concepts described above.

Do not recreate functionality merely because it is described here.

Before changing behavior:

1. Inspect the existing implementation.
2. Identify how the current feature works.
3. Verify whether the requirement already exists in some form.
4. Modify the existing implementation where appropriate.
5. Avoid introducing duplicate concepts or parallel implementations.

This document describes the product/domain context and intended direction. It is not a complete technical specification.

When implementation details are unclear, use the codebase as the source of truth for current behavior and this document as the source of product intent.

## Picking Flow

This section describes how picking works today. As elsewhere in this document, the codebase is the source of truth for details.

### Starting the Draft

Nothing can be selected until the **host** starts the draft. Only the host can do this, from the draft's details page.

The host can start the draft **at any time**, as long as:

* every participant has joined and has a place in the order, and
* the draft has items to select.

The start time is a schedule, not a rule: the picking page shows a countdown to it so participants know when to show up, and then "waiting for the host". It never starts the draft and never stops the host from starting early. Time passing, or people looking at the page, never starts a draft either.

Starting begins the first participant's turn and their clock.

Drafts that already had selections when this rule was introduced count as started.

### Turns and the Timer

Only the participant whose turn is currently active can successfully make a selection. This is enforced on the server, not only in the UI.

Each turn lasts as long as the draft's selection timer. When the timer expires the turn is **skipped**: the participant loses that turn (they are not removed from the draft), the next participant's clock starts, and everyone is told. Skipped turns do not use up items, so a draft only completes when every item has been selected.

* The server's clock decides when time is up. Clients show a countdown but cannot skip a turn early.
* A selection that arrives after the deadline is refused.
* There is no background worker. An expired turn is skipped when someone next asks; the picking page asks when its countdown reaches zero. At most one turn is skipped per check, and the next turn's clock starts when the skip is recorded, so a long absence produces one skipped turn rather than a pile of them.
* Turn order is round-robin and does not reverse.

### Real-Time Updates

Every change (the draft starting, a selection, a skipped turn) is broadcast to the draft's participants on a private channel using Laravel Reverb, so the picking page updates without a refresh. The payload is the full draft state, and clients ignore any state older than the one they already hold.

If Reverb is not configured, or the connection drops, the page falls back to polling the draft state endpoint. That endpoint is also how clients recover after a reconnect.

### Security

The backend independently enforces draft membership, participant identity (always the logged-in user, never a value sent by the client), the current turn, the deadline, item availability, and whether the host has started the draft. Only the host can start it, only participants can pick, and only the host and participants can read the draft state or listen to its channel.

Concurrent requests are serialised on the draft, and a unique constraint stops the same item being claimed twice.

### Open Questions

* Drafts created before owners were recorded have no host, so nobody can start them (unless they already had selections).
* A draft where nobody ever picks never completes: skipped turns keep rotating for as long as someone is watching.
* What should happen to a participant who is skipped repeatedly (auto-pick, removal) is undecided.
* Only round-robin order is supported; there is no snake (reversing) order.
