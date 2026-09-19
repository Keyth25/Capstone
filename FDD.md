# Functional Decomposition Diagram (FDD)

**System:** Interactive Cemetery Mapping and Management System

The system is decomposed into five major functional modules, each broken down into its sub-functions.

## Diagram

```
Cemetery Mapping and Management System
│
├── 1.0 Interactive Cemetery Mapping
│   ├── 1.1 Digital Map Display
│   │   ├── 1.1.1 Render cemetery layout (sections, blocks, plots)
│   │   ├── 1.1.2 Color-code plot status (available / reserved / occupied)
│   │   └── 1.1.3 Zoom, pan, and section filtering
│   ├── 1.2 Grave / Deceased Search
│   │   ├── 1.2.1 Search by name, date of burial, or plot number
│   │   └── 1.2.2 Locate and highlight plot on the map
│   ├── 1.3 Plot Information Viewing
│   │   ├── 1.3.1 View occupant details and burial records
│   │   ├── 1.3.2 View plot status, type, and price
│   │   └── 1.3.3 View plot location (section / block / lot)
│   └── 1.4 Map Administration
│       ├── 1.4.1 Manage sections and plot layout
│       ├── 1.4.2 Define plot coordinates and shapes
│       └── 1.4.3 Set cemetery perimeter and pathways
│
├── 2.0 Real-Time Voice Navigation
│   ├── 2.1 Destination Selection
│   │   ├── 2.1.1 Select grave/plot from search results or map tap
│   │   └── 2.1.2 Confirm navigation target
│   ├── 2.2 Route Computation
│   │   ├── 2.2.1 Compute shortest path along cemetery pathways
│   │   └── 2.2.2 Re-route when visitor deviates from path
│   ├── 2.3 Turn-by-Turn Guidance
│   │   ├── 2.3.1 Generate voice instructions (text-to-speech)
│   │   ├── 2.3.2 Display visual direction cues on the map
│   │   └── 2.3.3 Announce distance remaining and arrival
│   └── 2.4 Location Tracking
│       ├── 2.4.1 Track visitor position via device GPS
│       └── 2.4.2 Update position and heading in real time
│
├── 3.0 Plot Availability and Reservation Management
│   ├── 3.1 Plot Availability Display
│   │   ├── 3.1.1 Show real-time plot status on map and list
│   │   └── 3.1.2 Filter plots by section, type, and status
│   ├── 3.2 Reservation Processing
│   │   ├── 3.2.1 Submit reservation request (visitor)
│   │   ├── 3.2.2 Approve / decline reservation (admin)
│   │   └── 3.2.3 Cancel or expire reservations
│   ├── 3.3 Plot Assignment and Records
│   │   ├── 3.3.1 Assign plot to deceased/beneficiary
│   │   ├── 3.3.2 Record burial and occupant details
│   │   └── 3.3.3 Update plot status upon burial
│   └── 3.4 Payment Management
│       ├── 3.4.1 Record reservation / plot payments
│       ├── 3.4.2 Track payment status and history
│       └── 3.4.3 Issue payment confirmation
│
├── 4.0 Burial Scheduling and Workforce Management
│   ├── 4.1 Burial Scheduling
│   │   ├── 4.1.1 Create and manage burial schedule entries
│   │   ├── 4.1.2 View schedule calendar / list
│   │   └── 4.1.3 Detect schedule and plot conflicts
│   ├── 4.2 Workforce Assignment
│   │   ├── 4.2.1 Assign diggers and maintenance personnel to tasks
│   │   └── 4.2.2 Notify assigned staff of task details
│   └── 4.3 Task Monitoring
│       ├── 4.3.1 Track task status (pending / in progress / done)
│       ├── 4.3.2 Record staff work history
│       └── 4.3.3 Submit maintenance requests and reports
│
├── 5.0 Notification and Announcement System
│   ├── 5.1 In-System Notifications
│   │   ├── 5.1.1 Send reservation confirmations and updates
│   │   ├── 5.1.2 Send burial schedule reminders
│   │   └── 5.1.3 Send staff task assignments
│   ├── 5.2 Announcements
│   │   ├── 5.2.1 Post cemetery announcements (admin)
│   │   └── 5.2.2 View announcements (visitors and staff)
│   └── 5.3 Notification Management
│       ├── 5.3.1 Mark notifications as read / unread
│       └── 5.3.2 View notification history
│
└── 6.0 User and Access Management (Supporting Function)
    ├── 6.1 Authentication
    │   ├── 6.1.1 User login / logout
    │   └── 6.1.2 Role-based access (admin / staff / visitor)
    └── 6.2 Account Maintenance
        ├── 6.2.1 Manage admin and staff accounts
        └── 6.2.2 Manage visitor/user accounts
```

## Module Descriptions

| Module | Function | Sub-Functions |
|---|---|---|
| 1.0 | Interactive Cemetery Mapping | Digital map display, grave search, plot info viewing, map administration |
| 2.0 | Real-Time Voice Navigation | Destination selection, shortest-path routing, turn-by-turn voice guidance, GPS tracking |
| 3.0 | Plot Availability & Reservation | Real-time plot status, reservation request/approval, plot assignment, payments |
| 4.0 | Burial Scheduling & Workforce | Burial schedule management, staff assignment, task monitoring and work history |
| 5.0 | Notification & Announcements | In-system notifications, admin announcements, notification management |
| 6.0 | User & Access Management | Authentication, role-based access, account maintenance |
