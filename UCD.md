# Use Case Diagram (UCD)

**System:** Interactive Cemetery Mapping and Management System

Derived from the Functional Decomposition Diagram (FDD) and wired to match the
System Flow Diagram (`FLOW.svg`). Three actors — Visitor, Administrator, and
Cemetery Staff — connect to their use cases (outer columns), while the middle
column holds the **system connector use cases** that link one actor's action to
another actor's response. See `UCD.svg` for the rendered diagram.

> Note: `Login / Authenticate` is drawn once per actor group (top of each
> column) so no association lines cross — it is the same use case for all three
> roles (FDD 6.1.2).

## How the connections flow

```
RESERVATION LOOP
  Submit Reservation Request ──<<include>>──► Send Reservation Notification
                                                    │ notifies
  Approve / Decline Reservations ◄──────────────────┘
        │──<<include>>──► Send Reservation Status Notification
                              │ notifies
  View & Manage Notifications ◄┘        (visitor sees the decision)

PAYMENT LOOP
  Make Payment & View History ──<<include>>──► Record Payment Transaction
                                                    │ recorded in
  Manage Payments ◄─────────────────────────────────┘

NAVIGATION
  Search Grave / Deceased ◄──<<extend>>── Navigate to Grave
                                             │──<<include>>──► Compute Shortest Route
                                             └──<<include>>──► Track Visitor GPS Location

WORKFORCE LOOP
  Assign Workforce to Tasks ──<<include>>──► Notify Assigned Staff
                                                    │ notifies
  View Assigned Tasks (Staff) ◄───────────────────────┘
  Update Task Status / Submit Maintenance Report ──► reviewed via
  Monitor Task Progress / Review Maintenance Reports (Admin)

MAINTENANCE LOOP
  Submit Maintenance Request ──<<include>>──► Log Maintenance Request
                                             (reviewed by admin)

SCHEDULING
  Manage Burial Schedule ──<<include>>──► Detect Schedule Conflicts
                        ──<<include>>──► Send Burial Schedule Reminder

RESERVATION PRECONDITION
  Submit Reservation Request ──<<include>>──► View Plot Availability
```

## Actors

| Actor | Description |
|---|---|
| **Visitor** | Registered user who browses the map, searches graves, navigates to plots, requests reservations, pays, and submits maintenance requests. |
| **Administrator** | Manages the map layout, reservations, plot assignment, payments, burial schedules, workforce, announcements, accounts, and reviews staff work. |
| **Cemetery Staff** | Diggers / maintenance personnel who receive task assignments, update task status, submit work reports, and view announcements. |

## Use Cases Mapped to FDD

| Actor | Use Case | FDD Reference |
|---|---|---|
| Visitor | View Interactive Cemetery Map | 1.1 Digital Map Display |
| Visitor | Search Grave / Deceased | 1.2 Grave / Deceased Search |
| Visitor | Navigate to Grave (Voice Guidance) | 2.0 Real-Time Voice Navigation |
| Visitor | View Plot Information | 1.3 Plot Information Viewing |
| Visitor | View Plot Availability | 3.1 Plot Availability Display |
| Visitor | Submit Reservation Request | 3.2.1 Submit reservation request |
| Visitor | View & Manage Notifications | 5.3 Notification Management |
| Visitor | Make Payment & View History | 3.4 Payment Management |
| Visitor | Submit Maintenance Request | 4.3.3 Maintenance requests |
| Visitor | View Announcements | 5.2.2 View announcements |
| Administrator | Manage Map Layout | 1.4 Map Administration |
| Administrator | Approve / Decline Reservations | 3.2.2, 3.2.3 |
| Administrator | Assign Plot & Record Burial | 3.3 Plot Assignment and Records |
| Administrator | Manage Payments | 3.4 Payment Management |
| Administrator | Manage Burial Schedule | 4.1 Burial Scheduling |
| Administrator | Assign Workforce to Tasks | 4.2 Workforce Assignment |
| Administrator | Post Announcements | 5.2.1 Post cemetery announcements |
| Administrator | Manage User Accounts | 6.2 Account Maintenance |
| Administrator | Monitor Task Progress | 4.3.1, 4.3.2 Task Monitoring |
| Administrator | Review Maintenance Reports | 4.3.3 Maintenance requests and reports |
| Cemetery Staff | View Assigned Tasks | 4.2.2 Notify assigned staff |
| Cemetery Staff | Update Task Status | 4.3.1 Track task status |
| Cemetery Staff | Submit Maintenance Report | 4.3.3 Submit maintenance requests and reports |
| Cemetery Staff | View Announcements & Notifications | 5.2.2, 5.3 |
| All actors | Login / Authenticate | 6.1 Authentication |

## System Connector Use Cases (middle column)

These are the use cases that *connect* an actor's action to the next actor's
response — mirroring the System lane of `FLOW.svg`.

| Use Case | Connects | FDD Reference |
|---|---|---|
| Compute Shortest Route | `<<include>>` of Navigate to Grave | 2.2 |
| Track Visitor GPS Location | `<<include>>` of Navigate to Grave | 2.4 |
| Send Reservation Notification | `<<include>>` of Submit Reservation Request → **notifies** Approve / Decline Reservations | 5.1.1 |
| Send Reservation Status Notification | `<<include>>` of Approve / Decline Reservations → **notifies** View & Manage Notifications | 5.1.1 |
| Record Payment Transaction | `<<include>>` of Make Payment → **recorded in** Manage Payments | 3.4 |
| Detect Schedule Conflicts | `<<include>>` of Manage Burial Schedule | 4.1.3 |
| Send Burial Schedule Reminder | `<<include>>` of Manage Burial Schedule | 5.1.2 |
| Notify Assigned Staff | `<<include>>` of Assign Workforce → **notifies** View Assigned Tasks | 4.2.2, 5.1.3 |
| Log Maintenance Request | `<<include>>` of Submit Maintenance Request → reviewed via Review Maintenance Reports | 4.3.3 |

## Relationships

- **Blue arrows** = cross-actor delivery/flow connections (notification or
  record reaching the next actor's use case), matching `FLOW.svg`.
- **Dashed orange arrows** = `<<include>>` / `<<extend>>` UML relationships.
- **Submit Reservation Request** `<<include>>` **View Plot Availability** —
  real-time status is consulted before requesting a plot.
- **Navigate to Grave** `<<extend>>` **Search Grave / Deceased** — navigation
  optionally follows a search (destination may also be picked on the map).
- Staff's **Update Task Status** and **Submit Maintenance Report** feed the
  admin's **Monitor Task Progress** and **Review Maintenance Reports**
  (drawn explicitly in `FLOW.svg`).
