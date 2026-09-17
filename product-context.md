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

The creator currently:

* Defines the participants.
* Creates/configures the interests, maps, or other selectable content required by the draft.
* Defines the draft rules.
* Sets the number of participants.
* Sets the selection timer.

The exact implementation of these features should be determined by inspecting the existing codebase rather than assumed from this document.

## Participants

Currently, participants can only be users who already have accounts on the platform.

Participants are presently added using their **username**.

This is a limitation that is intended to change.

The desired direction is to allow participants to be added using their **email address**, which would allow people who do not already have platform accounts to participate/join a draft.

This requirement should be considered when modifying participant-related functionality.

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
* Selection timer

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

## Picking Flow — Current Limitations

The current picking/selection flow has some important limitations that need to be addressed.

### Turn Enforcement

Participants should only be able to make a selection when it is their assigned turn.

Currently, the picking flow does not adequately enforce this requirement.

The system should treat the participant's turn as a server-side rule, not merely something enforced by the frontend UI.

A participant should not be able to select an item by directly calling an endpoint or otherwise bypassing the UI when it is not their turn.

When investigating or modifying this functionality, inspect the complete flow, including:

* How the current turn is determined.
* Where turn validation occurs.
* How a participant submits a selection.
* Whether the backend independently verifies that the participant is allowed to select.
* Whether race conditions could allow multiple participants to select simultaneously.
* Whether a user can manipulate requests to select outside their turn.

The required behavior is:

> Only the participant whose turn is currently active can successfully make a selection.

### Broadcasting / Real-Time Updates

The current picking flow does not have a proper broadcasting or real-time update mechanism.

When one participant makes a selection, other participants may not immediately receive an updated draft state.

The picking experience should eventually support real-time state updates so participants can see important changes such as:

* Whose turn it is.
* When a selection is made.
* Which item was selected.
* Which items are no longer available.
* When the next participant's turn begins.
* Relevant timer/state changes.

The implementation approach should be investigated based on the existing application architecture rather than assumed in advance.

### Security

The picking flow requires a security review.

The fact that an item appears unavailable or that it is not a participant's turn in the frontend must not be considered sufficient protection.

The backend must independently enforce the rules governing:

* Draft membership.
* Participant identity.
* Current selection turn.
* Item availability.
* Whether a selection is still valid.
* Whether the participant is allowed to perform the action.

The application should also account for concurrent requests and race conditions around selection.

For example, two requests attempting to claim the same item at approximately the same time should not result in both participants successfully claiming it.

Codex should investigate the existing implementation and identify the actual enforcement points, rather than assuming that the current UI behavior represents the application's security model.
