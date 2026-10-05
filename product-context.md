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

The creator also chooses, per draft:

* the **template** — Giveaway (take turns claiming items) or Bidding (a live auction, item by item), and, for Giveaway, its **mode** — items (claim them) or money (the host defines a payout table instead),
* the **visibility** — Private (the owner invites specific people by email) or Public (anyone with a shareable link can join, up to a limit the owner sets),
* the **order mode** — who decides the order participants pick in (Giveaway items or money) or items go up for auction in (Bidding): the owner, first come first served, or random, and
* the **participant limit** — 1 to 100, applying to either visibility.

See "Templates" and "Visibility" below, "Money Split Flow" for Giveaway's money mode, and "Bidding Flow" for how Bidding differs from picking.

The exact implementation of these features should be determined by inspecting the existing codebase rather than assumed from this document.

### Editing a Session

Until the host starts the draft, they can change any of it from the session's edit page:

* the settings: name, description, turn timer, scheduled start, template, visibility, order mode and participant limit,
* the items: add, rename, replace or remove photos, remove, and — for Bidding with order mode "the owner" — rearrange their auction order; for Giveaway's money mode, this is a **payout table** instead (see "Money Split Flow") — add, change or remove tiers,
* the participants: invite more people, remove people, and
* the order: the participant order (Giveaway items or money) or item order (Bidding), when order mode is "the owner"; fcfs and random need nothing set manually.

The number of participants and items entered when the session was created is only a starting point. The real counts are what is shown.

Once the host starts the draft, all of this is fixed. That is enforced on the server when each change is saved, so an edit and the host pressing Start can never both take effect. A Bidding draft counts as started the moment it has a bid recorded, the same way a Giveaway draft counts as started the moment it has a selection recorded.

Edits are announced live. Anyone already on the picking/bidding page has their page reloaded so they see the current items and players. A participant who is removed is not told; they simply lose access to the session.

## Templates

Every draft has a **type**: `giveaway` or `bidding`. It is chosen at creation and can be changed from the edit page until the draft starts.

* **Giveaway** is the original behavior: participants take turns claiming items in an assigned order. See "Selection Order" and "Picking Flow". Giveaway has a second **mode**, chosen alongside the template: `items` (the default, described above) or `money` — see "Money Split Flow".
* **Bidding** is a live auction: items go up one at a time, in a fixed sequence, and participants bid on whichever is currently open. See "Bidding Flow".

The two templates share almost everything else — creation, editing, participants, visibility, real-time updates, and the underlying turn clock — and differ only in what happens once the draft starts and what "order mode" orders (participants vs. items). Giveaway's `money` mode reuses the same participant-order machinery (see "Selection Order") but nothing else about "starts a turn-based session" applies to it — see "Money Split Flow" for how it differs.

## Visibility

Every draft is **private** or **public**.

* **Private** is the original behavior: the owner invites specific people by email, each with their own invitation link.
* **Public** drafts have a single shareable link (shown only to the owner, alongside the private invitation links). Anyone logged in can open it and join themself. Joining closes once the draft's **participant limit** is reached, and closes for good once the draft starts. Removing a participant frees their slot again.

The participant limit applies to both visibilities — for Private it caps how many people can be invited in total, for Public it is the self-join cap — and is set by the owner at creation or from the edit page, from 1 to 100.

A public draft's link is generated once, the first time visibility becomes public, and stays the same afterward even if the owner changes other settings or switches visibility back and forth.

## Participants

Private drafts invite participants by **email address**; the owner shares an invitation link with each one. Public drafts are joined with the one shared link instead — see "Visibility" above. Either way, joining means a `Team` row gets a `user_id`.

**Private sessions need an account; public ones do not.**

* **Private:** the invitation is tied to an email address, so the invited person needs an account. Opening the link while logged out sends them to log in or sign up and then carries on to the invitation. It only works for the account whose email matches the invited one, an email can be invited once per draft, and a seat someone has already taken cannot be taken again.
* **Public:** someone who opens the link without being logged in is asked only for a name and plays as a **guest**: a real user with no email and no password of their own, kept logged in by their session (remembered across browser restarts). Because a guest is an ordinary user underneath, turn enforcement, permissions and live updates treat them exactly like any other participant. Joining twice is harmless, not an error. A public link has no per-person token, so a guest who logs out or loses their session and opens it again becomes a new guest with a new seat.
* A guest is turned away from a private invitation with a prompt to sign up, and from hosting: **hosting needs an account** too (a guest is only held in place by a cookie, and a session they created would be lost with it), as does the profile page. Signing up while playing as a guest upgrades that same user, so they keep the seats they already hold.

The creation wizard's "how many people" step differs by visibility: for **Private**, the number the owner enters is both how many people to invite by email right away and the participant limit — the wizard asks for that many email addresses before moving on. For **Public**, that same number is only the self-join cap; nobody is pre-invited by email during creation, since people are expected to join through the link. Either way, the owner can invite specific people by email afterward from the edit page, whether or not any were invited at creation.

Users are identified by their **username**. The `users` table has no separate "name" field. A guest's username is just the name they typed; names are not unique, so two guests can share one.

Open questions for guests:

* Guest accounts are never cleaned up: a guest who walks away leaves a user row behind.
* A guest who joined through a public link and then loses their session cannot get that seat back, since a public link has no per-person token to return with. The host can remove the orphaned seat from the edit page, which frees the slot.
* Creating guests is rate limited per client (20 a minute), which stops a script flooding the table but is not a defence against a determined one.
* There is no way yet for someone who plays as a guest to sign in to an *existing* account and bring their seats with them; signing up upgrades the guest in place, logging in to an older account does not.

## Selection Order

**Order mode** decides how order is settled, for either template:

* **the owner decides** — the original behavior. The owner manually assigns the order from the edit page (Giveaway: participants; Bidding: items) before the draft can start.
* **first come, first served** — assigned automatically the instant someone joins (Giveaway) or the instant the owner adds an item (Bidding). The owner sets nothing.
* **random** — shuffled once, automatically, the moment the host starts the draft. Nothing is assigned before then, so the order is not visible in advance even to the owner.

For **Giveaway**, order mode governs **participant order** — who picks when:

1. Participant A
2. Participant B
3. Participant C
4. Participant D

Participants select in that order. After a participant selects an item, that item becomes unavailable for subsequent participants.

For **Bidding**, order mode governs **item order** instead — participants have no turn order at all; see "Bidding Flow".

The selection mechanism is a core part of the system and should remain independent of the specific type of item being selected.

## Current Draft Rules

The current draft configuration includes at least:

* Template (Giveaway or Bidding), and for Giveaway, its mode (items or money)
* Visibility (Private or Public) and participant limit
* Order mode (owner-decided, first come first served, or random)
* Number of participants
* Start date and time
* Selection timer (seconds per turn — Giveaway items; seconds per item — Bidding; unused for Giveaway money mode, which has no clock)

The start time is entered in the creator's local time and stored in UTC. It is a schedule the picking/bidding/results page counts down to, not a rule: the host can start a draft before or after it, at any time — see "Starting the Draft".

Additional rules may be introduced as the product evolves.

When implementing new rules, avoid unnecessarily coupling them to a specific draft use case.

## Product Direction

The original concept was designed around selecting locations from a map.

The broader goal is to provide a reusable turn-based selection system.

Two templates exist today — Giveaway (turn-based picking) and Bidding (a live auction) — see "Templates". Potential further use cases include:

* Map/location drafts (Giveaway)
* Allocation
* Yard-sale style selection (Giveaway or Bidding)
* Other scenarios where participants select or bid on items in sequence

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

This section describes how picking works today, for **Giveaway** drafts. See "Bidding Flow" for Bidding. As elsewhere in this document, the codebase is the source of truth for details.

### Starting the Draft

Nothing can be selected until the **host** starts the draft. Only the host can do this, from the draft's details page.

The host can start the draft **at any time**, as long as:

* every participant has joined, and — only under order mode "the owner decides" — every participant already has a place in the order (fcfs and random settle the order themselves; see "Selection Order"), and
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

### When Every Item Is Taken

The draft is complete and the picking page swaps the item board and the order list for a **results table** shown to every participant and to the host: each player in selection order, with the items they took in the order they took them. Someone who ended up with nothing (more players than items) is still listed. The results arrive in the same state everything else uses, only once the draft is complete, so a page that is already open shows them live and one opened afterwards shows them straight away. (Bidding's board already lists the winner of every item, so it has no separate table.)

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

## Money Split Flow

This section describes Giveaway's `money` mode — chosen instead of `items` at creation (see "Templates"). It shares "Starting the Draft" and "Selection Order" with Picking Flow above (same host-starts-it-whenever rule, same three order modes), but nothing else: there are no items, no turns, and no clock. As elsewhere in this document, the codebase is the source of truth for details.

### The Payout Table

Instead of adding items, the host defines a **payout table**: one or more tiers, each an inclusive rank range (e.g. "1–5") and an amount. Every participant whose eventual rank falls in that range is paid that tier's amount **each** — the amount is not a pool split between them. A single rank is a range where `rank_from` equals `rank_to` (e.g. "1–1" for a lone 1st-place payout). Tiers may not overlap each other. A rank not covered by any tier is paid nothing — this is by design, not an oversight, so the host can leave lower ranks unpaid without a tier for every rank.

Tiers are managed from the same wizard-step-then-edit-page pattern items use: added one batch at a time when the draft is created, and added, changed or removed individually from the edit page afterward, until the draft starts.

### Starting the Draft: a One-Time Reveal

There is no picking or bidding to do, so starting the draft is not the beginning of a session — it is a **one-time, live reveal** of the results. The host can start it at any time, the same as Picking Flow, once:

* every participant has joined, and — only under order mode "the owner decides" — every participant already has a place in the order (fcfs and random settle the order themselves; see "Selection Order"), and
* the draft has at least one payout tier.

Under order mode "random", the order is shuffled once, at the moment of starting — exactly as it is for Giveaway picking — so who ends up in which rank is not knowable in advance, even to the host. The moment the draft starts, every participant's rank and payout are fixed and visible to everyone at once: there is nothing left to happen afterward, so the draft is complete the instant it starts.

### Real-Time Updates

The reveal is broadcast live to everyone already on the results page, the same Reverb channel and polling-fallback mechanism Picking Flow and Bidding Flow use. There is no countdown to a turn deadline, since there is no turn; the only countdown shown is to the draft's scheduled start time, before the host has pressed Start.

### Security

The same guarantees as Picking Flow's "Security" section apply, minus anything about turns or item availability, since neither exists here: the backend independently enforces draft membership, that only the host can start the draft, that payout tiers cannot overlap or be edited once the draft has started, and that only the host and participants can read the draft state or listen to its channel.

### Open Questions

* Ranks not covered by any tier are paid nothing, with no UI distinction from "the host forgot a tier" — the host is expected to notice this from the payout table itself before starting.
* There is no currency/formatting system — amounts are plain whole numbers, with no assumed currency symbol.
* Nothing stops a host from setting up a payout table where the total payout is inconsistent with any external prize pool; the system does not track or validate a total budget.

## Bidding Flow

This section describes how Bidding works today. It shares "Starting the Draft" and "Real-Time Updates" almost exactly with Picking Flow above (same host-starts-it rule, same turn clock reused as each item's window, same Reverb broadcast and polling fallback), so only what differs is spelled out here.

### Auction Mechanics

There is no turn order among participants at all. Instead, order mode governs the **item** sequence — see "Selection Order" — and items go up for auction one at a time, in that sequence, with no "nominator": whichever item is next just opens.

Once an item is open:

* **any** participant may bid on it, at any time, as many times as they like — including raising their own leading bid,
* a bid must be a positive whole number, strictly **higher** than the current leading bid (or any amount, for the first bid on an item) — there is no minimum increment,
* the current leading bid and who placed it are visible live to everyone, the same way whose turn it is is visible in Giveaway, and
* there is no spending budget: a participant's bids are not weighed against anything they have bid on other items.

When an item's window (the draft's selection timer, reused as "seconds per item") runs out:

* if it has at least one bid, the **highest** bid wins it — recorded as that item's frozen outcome, and
* if it has none, the item goes **unsold** — nobody is removed or penalized, and the item is simply left off the results.

Either way, the next item's window starts immediately, the same way the next participant's turn starts immediately after a Giveaway pick or skip. The draft is **complete** once every item has closed, sold or not — so, unlike Giveaway, a Bidding draft can complete without every item finding a buyer.

### Security

The same guarantees as Picking Flow's "Security" section apply, with bids in place of selections: the backend independently enforces draft membership, bidder identity (always the logged-in user), that the bid targets the item actually open, the deadline, and the strictly-higher rule. Concurrent bids on the same item are serialised on the draft, the same lock Giveaway picks use.

### Open Questions

* No budget/currency system exists yet — bidding is unlimited, per the product decision to keep the first version simple.
* No reserve price or minimum opening bid exists — the first bid on an item can be any positive amount.
* There is no dedicated creation-wizard step for arranging item order (unlike Giveaway's participant-order step); a host who wants "the owner decides" item order sets it from the edit page, after the items exist.
* No notification exists for being outbid — participants only find out by watching the page live or refreshing it.
