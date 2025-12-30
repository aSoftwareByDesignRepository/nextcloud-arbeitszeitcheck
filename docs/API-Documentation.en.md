# API Documentation – ArbeitszeitCheck (TimeGuard)

**Version:** 1.0.0  
**Base URL:** `/apps/arbeitszeitcheck`  
**API Version:** v1 (implicit)

## Table of Contents

1. [Authentication](#authentication)
2. [General Information](#general-information)
3. [Time Tracking API](#time-tracking-api)
4. [Time Entries API](#time-entries-api)
5. [Absence Management API](#absence-management-api)
6. [Manager API](#manager-api)
7. [Compliance API](#compliance-api)
8. [Reporting API](#reporting-api)
9. [Export API](#export-api)
10. [GDPR API](#gdpr-api)
11. [Admin API](#admin-api)
12. [Settings API](#settings-api)
13. [Error Handling](#error-handling)

---

## Authentication

All API endpoints require authentication via Nextcloud session. The app uses Nextcloud's built-in authentication system:

- **Session-based authentication**: Users must be logged into Nextcloud
- **CSRF protection**: All state-changing requests (POST, PUT, DELETE) require CSRF tokens
- **Role-based access**: Endpoints respect user roles (employee, manager, admin)

### CSRF Token

For state-changing requests, include the CSRF token in the request header:

```http
X-Requested-With: XMLHttpRequest
```

Or include it in the request body/form data (Nextcloud handles this automatically for same-origin requests).

---

## General Information

### Response Format

All API endpoints return JSON responses with the following structure:

**Success Response:**
```json
{
  "success": true,
  "data": { ... },
  "message": "Optional success message"
}
```

**Error Response:**
```json
{
  "success": false,
  "error": "Error message",
  "code": "ERROR_CODE"
}
```

### HTTP Status Codes

- `200 OK` - Successful GET, PUT requests
- `201 Created` - Successful POST requests (resource created)
- `400 Bad Request` - Invalid request parameters
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Insufficient permissions
- `404 Not Found` - Resource not found
- `500 Internal Server Error` - Server error

### Pagination

List endpoints support pagination via query parameters:

- `limit` (integer, default: 25) - Number of items per page
- `offset` (integer, default: 0) - Number of items to skip

**Example:**
```http
GET /apps/arbeitszeitcheck/api/time-entries?limit=50&offset=0
```

**Response includes:**
```json
{
  "success": true,
  "entries": [ ... ],
  "total": 150,
  "limit": 50,
  "offset": 0
}
```

### Date Formats

All dates use ISO 8601 format: `YYYY-MM-DD` (e.g., `2024-01-15`)

Time values use 24-hour format: `HH:MM:SS` (e.g., `09:00:00`)

---

## Time Tracking API

### Clock In

Start tracking time for the current day.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/clock/in`

**Request Parameters:**
- `projectCheckProjectId` (string, optional) - Project ID from ProjectCheck app
- `description` (string, optional) - Description for the time entry

**Example Request:**
```http
POST /apps/arbeitszeitcheck/api/clock/in
Content-Type: application/json

{
  "projectCheckProjectId": "123",
  "description": "Working on feature X"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "timeEntry": {
    "id": 1,
    "userId": "user123",
    "startTime": "2024-01-15T09:00:00Z",
    "status": "active",
    "durationHours": 0.0,
    "description": "Working on feature X",
    "projectCheckProjectId": "123"
  }
}
```

**Error Responses:**
- `400` - User already has active time entry
- `400` - Insufficient rest period (less than 11 hours since last shift)

---

### Clock Out

Stop tracking time for the current active entry.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/clock/out`

**Example Request:**
```http
POST /apps/arbeitszeitcheck/api/clock/out
```

**Success Response (200):**
```json
{
  "success": true,
  "timeEntry": {
    "id": 1,
    "userId": "user123",
    "startTime": "2024-01-15T09:00:00Z",
    "endTime": "2024-01-15T17:30:00Z",
    "status": "completed",
    "durationHours": 8.5,
    "workingDurationHours": 8.0,
    "breakDurationHours": 0.5
  }
}
```

**Error Responses:**
- `400` - No active time entry found

---

### Get Clock Status

Get the current clock status (active entry, break status, etc.).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/clock/status`

**Success Response (200):**
```json
{
  "success": true,
  "status": {
    "isClockedIn": true,
    "hasActiveEntry": true,
    "activeEntry": {
      "id": 1,
      "startTime": "2024-01-15T09:00:00Z",
      "durationHours": 4.5,
      "isOnBreak": false,
      "breakStartTime": null,
      "breakDurationHours": 0.0
    },
    "currentTime": "2024-01-15T13:30:00Z"
  }
}
```

---

### Start Break

Start a break for the current active time entry.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/break/start`

**Success Response (200):**
```json
{
  "success": true,
  "timeEntry": {
    "id": 1,
    "isOnBreak": true,
    "breakStartTime": "2024-01-15T12:00:00Z"
  }
}
```

**Error Responses:**
- `400` - No active time entry
- `400` - Already on break

---

### End Break

End the current break.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/break/end`

**Success Response (200):**
```json
{
  "success": true,
  "timeEntry": {
    "id": 1,
    "isOnBreak": false,
    "breakStartTime": "2024-01-15T12:00:00Z",
    "breakEndTime": "2024-01-15T12:30:00Z",
    "breakDurationHours": 0.5
  }
}
```

**Error Responses:**
- `400` - No active time entry
- `400` - Not currently on break

---

## Time Entries API

### List Time Entries

Get a paginated list of time entries for the current user.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/time-entries`

**Query Parameters:**
- `start_date` (string, optional) - Filter by start date (Y-m-d)
- `end_date` (string, optional) - Filter by end date (Y-m-d)
- `status` (string, optional) - Filter by status: `active`, `completed`, `break`, `pending_approval`, `rejected`
- `limit` (integer, optional, default: 25) - Items per page
- `offset` (integer, optional, default: 0) - Pagination offset

**Example Request:**
```http
GET /apps/arbeitszeitcheck/api/time-entries?start_date=2024-01-01&end_date=2024-01-31&status=completed&limit=50
```

**Success Response (200):**
```json
{
  "success": true,
  "entries": [
    {
      "id": 1,
      "userId": "user123",
      "startTime": "2024-01-15T09:00:00Z",
      "endTime": "2024-01-15T17:30:00Z",
      "breakStartTime": "2024-01-15T12:00:00Z",
      "breakEndTime": "2024-01-15T12:30:00Z",
      "durationHours": 8.5,
      "breakDurationHours": 0.5,
      "workingDurationHours": 8.0,
      "description": "Regular work day",
      "status": "completed",
      "isManualEntry": false,
      "projectCheckProjectId": null,
      "createdAt": "2024-01-15T09:00:00Z",
      "updatedAt": "2024-01-15T17:30:00Z"
    }
  ],
  "total": 1,
  "limit": 50,
  "offset": 0
}
```

---

### Get Time Entry

Get a single time entry by ID.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/time-entries/{id}`

**Path Parameters:**
- `id` (integer, required) - Time entry ID

**Success Response (200):**
```json
{
  "success": true,
  "entry": {
    "id": 1,
    "userId": "user123",
    "startTime": "2024-01-15T09:00:00Z",
    "endTime": "2024-01-15T17:30:00Z",
    "durationHours": 8.5,
    "status": "completed"
  }
}
```

**Error Responses:**
- `404` - Time entry not found
- `403` - Access denied (not your entry)

---

### Create Time Entry (Manual)

Create a manual time entry (for retroactive entries or corrections).

**Endpoint:** `POST /apps/arbeitszeitcheck/api/time-entries`

**Request Body:**
```json
{
  "date": "2024-01-15",
  "hours": 8.5,
  "description": "Manual entry for missed clock-in",
  "projectCheckProjectId": "123"
}
```

**Required Fields:**
- `date` (string) - Date in Y-m-d format
- `hours` (float) - Number of hours worked

**Optional Fields:**
- `description` (string) - Description/justification (mandatory for manual entries)
- `projectCheckProjectId` (string) - Project ID from ProjectCheck

**Success Response (201):**
```json
{
  "success": true,
  "entry": {
    "id": 2,
    "userId": "user123",
    "startTime": "2024-01-15T09:00:00Z",
    "endTime": "2024-01-15T17:30:00Z",
    "durationHours": 8.5,
    "status": "completed",
    "isManualEntry": true,
    "description": "Manual entry for missed clock-in"
  }
}
```

**Error Responses:**
- `400` - Missing required fields (date, hours)
- `400` - Invalid date format
- `400` - Invalid hours value (must be > 0 and <= 24)

---

### Update Time Entry

Update an existing time entry (only if editable).

**Endpoint:** `PUT /apps/arbeitszeitcheck/api/time-entries/{id}`

**Path Parameters:**
- `id` (integer, required) - Time entry ID

**Request Body:**
```json
{
  "date": "2024-01-15",
  "hours": 8.0,
  "description": "Updated description",
  "projectCheckProjectId": "456"
}
```

**Note:** All fields are optional. Only provided fields will be updated.

**Success Response (200):**
```json
{
  "success": true,
  "entry": {
    "id": 1,
    "durationHours": 8.0,
    "description": "Updated description"
  }
}
```

**Error Responses:**
- `404` - Time entry not found
- `403` - Access denied or entry not editable (e.g., already approved)
- `400` - Invalid parameters

---

### Request Time Entry Correction

Request a correction for a time entry that cannot be directly edited (e.g., already completed/approved).

**Endpoint:** `POST /apps/arbeitszeitcheck/api/time-entries/{id}/request-correction`

**Path Parameters:**
- `id` (integer, required) - Time entry ID

**Request Body:**
```json
{
  "justification": "The end time was incorrectly recorded",
  "newDate": "2024-01-15",
  "newHours": 7.5,
  "newDescription": "Corrected description"
}
```

**Required Fields:**
- `justification` (string) - Reason for correction request

**Optional Fields:**
- `newDate` (string) - Corrected date
- `newHours` (float) - Corrected hours
- `newDescription` (string) - Corrected description

**Success Response (200):**
```json
{
  "success": true,
  "message": "Correction request submitted successfully",
  "entry": {
    "id": 1,
    "status": "pending_approval",
    "justification": "The end time was incorrectly recorded"
  }
}
```

**Error Responses:**
- `404` - Time entry not found
- `400` - Missing justification
- `400` - Entry already has pending correction request

---

### Delete Time Entry

Delete a time entry (only manual entries can be deleted).

**Endpoint:** `DELETE /apps/arbeitszeitcheck/api/time-entries/{id}`

**Path Parameters:**
- `id` (integer, required) - Time entry ID

**Success Response (200):**
```json
{
  "success": true,
  "message": "Time entry deleted successfully"
}
```

**Error Responses:**
- `404` - Time entry not found
- `403` - Cannot delete (not a manual entry or not your entry)

---

### Get Time Entry Statistics

Get summary statistics for time entries.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/time-entries/stats`

**Query Parameters:**
- `start_date` (string, optional) - Start date for period (Y-m-d)
- `end_date` (string, optional) - End date for period (Y-m-d)

**Success Response (200):**
```json
{
  "success": true,
  "stats": {
    "totalHours": 160.5,
    "totalDays": 20,
    "averageHoursPerDay": 8.025,
    "totalBreakHours": 10.0,
    "totalWorkingHours": 150.5,
    "period": {
      "start": "2024-01-01",
      "end": "2024-01-31"
    }
  }
}
```

---

### Get Overtime Information

Get overtime calculation for a specific period.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/time-entries/overtime`

**Query Parameters:**
- `period` (string, optional, default: `monthly`) - Period type: `daily`, `weekly`, `monthly`, `yearly`, `custom`
- `start_date` (string, optional) - Start date for custom period (Y-m-d)
- `end_date` (string, optional) - End date for custom period (Y-m-d)

**Success Response (200):**
```json
{
  "success": true,
  "overtime": {
    "period": "monthly",
    "startDate": "2024-01-01",
    "endDate": "2024-01-31",
    "requiredHours": 160.0,
    "workedHours": 165.5,
    "overtimeHours": 5.5,
    "isPositive": true
  }
}
```

---

### Get Overtime Balance

Get cumulative overtime balance (all time).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/time-entries/overtime/balance`

**Success Response (200):**
```json
{
  "success": true,
  "balance": {
    "totalOvertimeHours": 12.5,
    "isPositive": true,
    "lastCalculated": "2024-01-31T23:59:59Z"
  }
}
```

---

## Absence Management API

### List Absences

Get a paginated list of absence requests for the current user.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/absences`

**Query Parameters:**
- `status` (string, optional) - Filter by status: `pending`, `approved`, `rejected`, `deleted`
- `type` (string, optional) - Filter by type: `vacation`, `sick_leave`, `special_leave`, `unpaid_leave`
- `limit` (integer, optional, default: 25)
- `offset` (integer, optional, default: 0)

**Success Response (200):**
```json
{
  "success": true,
  "absences": [
    {
      "id": 1,
      "userId": "user123",
      "type": "vacation",
      "startDate": "2024-06-01",
      "endDate": "2024-06-05",
      "days": 5,
      "reason": "Summer vacation",
      "status": "approved",
      "approverComment": "Approved",
      "approvedAt": "2024-05-15T10:00:00Z",
      "createdAt": "2024-05-01T09:00:00Z"
    }
  ],
  "total": 1,
  "limit": 25,
  "offset": 0
}
```

---

### Get Absence

Get a single absence request by ID.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/absences/{id}`

**Path Parameters:**
- `id` (integer, required) - Absence ID

**Success Response (200):**
```json
{
  "success": true,
  "absence": {
    "id": 1,
    "type": "vacation",
    "startDate": "2024-06-01",
    "endDate": "2024-06-05",
    "days": 5,
    "status": "approved"
  }
}
```

---

### Create Absence Request

Create a new absence request.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/absences`

**Request Body:**
```json
{
  "type": "vacation",
  "start_date": "2024-06-01",
  "end_date": "2024-06-05",
  "reason": "Summer vacation"
}
```

**Required Fields:**
- `type` (string) - Absence type: `vacation`, `sick_leave`, `special_leave`, `unpaid_leave`
- `start_date` (string) - Start date (Y-m-d)
- `end_date` (string) - End date (Y-m-d)

**Optional Fields:**
- `reason` (string) - Reason/description

**Success Response (201):**
```json
{
  "success": true,
  "absence": {
    "id": 1,
    "type": "vacation",
    "startDate": "2024-06-01",
    "endDate": "2024-06-05",
    "days": 5,
    "status": "pending",
    "createdAt": "2024-05-01T09:00:00Z"
  }
}
```

**Error Responses:**
- `400` - Missing required fields
- `400` - Invalid date range (end before start)
- `400` - Overlapping absence requests

---

### Update Absence Request

Update an absence request (only if status is `pending`).

**Endpoint:** `PUT /apps/arbeitszeitcheck/api/absences/{id}`

**Path Parameters:**
- `id` (integer, required) - Absence ID

**Request Body:**
```json
{
  "start_date": "2024-06-02",
  "end_date": "2024-06-06",
  "reason": "Updated vacation dates"
}
```

**Note:** All fields are optional. Only provided fields will be updated.

**Success Response (200):**
```json
{
  "success": true,
  "absence": {
    "id": 1,
    "startDate": "2024-06-02",
    "endDate": "2024-06-06",
    "days": 5
  }
}
```

**Error Responses:**
- `404` - Absence not found
- `403` - Cannot update (already approved/rejected)

---

### Delete Absence Request

Delete an absence request (only if status is `pending`).

**Endpoint:** `DELETE /apps/arbeitszeitcheck/api/absences/{id}`

**Path Parameters:**
- `id` (integer, required) - Absence ID

**Success Response (200):**
```json
{
  "success": true,
  "message": "Absence request deleted successfully"
}
```

---

### Approve Absence (Manager)

Approve an absence request (manager only).

**Endpoint:** `POST /apps/arbeitszeitcheck/api/absences/{id}/approve`

**Path Parameters:**
- `id` (integer, required) - Absence ID

**Request Body:**
```json
{
  "comment": "Approved"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "absence": {
    "id": 1,
    "status": "approved",
    "approverComment": "Approved",
    "approvedAt": "2024-05-15T10:00:00Z"
  }
}
```

**Error Responses:**
- `404` - Absence not found
- `403` - Not authorized (not manager of this employee)
- `400` - Already approved/rejected

---

### Reject Absence (Manager)

Reject an absence request (manager only).

**Endpoint:** `POST /apps/arbeitszeitcheck/api/absences/{id}/reject`

**Path Parameters:**
- `id` (integer, required) - Absence ID

**Request Body:**
```json
{
  "comment": "Not enough vacation days remaining"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "absence": {
    "id": 1,
    "status": "rejected",
    "approverComment": "Not enough vacation days remaining"
  }
}
```

---

## Manager API

### Get Team Overview

Get overview of team members' current status (manager only).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/manager/team-overview`

**Query Parameters:**
- `limit` (integer, optional, default: 50)
- `offset` (integer, optional, default: 0)

**Success Response (200):**
```json
{
  "success": true,
  "team": [
    {
      "userId": "employee1",
      "displayName": "John Doe",
      "isClockedIn": true,
      "currentEntry": {
        "startTime": "2024-01-15T09:00:00Z",
        "durationHours": 4.5
      },
      "todayHours": 4.5,
      "weekHours": 32.0,
      "overtimeBalance": 2.5
    }
  ],
  "total": 1
}
```

---

### Get Pending Approvals

Get list of pending approvals (absences and time corrections) for team members.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/manager/pending-approvals`

**Query Parameters:**
- `type` (string, optional) - Filter by type: `absence`, `time_entry_correction`, or `all` (default)
- `limit` (integer, optional, default: 25)
- `offset` (integer, optional, default: 0)

**Success Response (200):**
```json
{
  "success": true,
  "approvals": {
    "absences": [
      {
        "id": 1,
        "userId": "employee1",
        "type": "vacation",
        "startDate": "2024-06-01",
        "endDate": "2024-06-05",
        "days": 5
      }
    ],
    "timeEntryCorrections": [
      {
        "id": 10,
        "userId": "employee2",
        "justification": "Forgot to clock out",
        "requestedAt": "2024-01-15T18:00:00Z"
      }
    ]
  },
  "total": {
    "absences": 1,
    "timeEntryCorrections": 1
  }
}
```

---

### Get Team Compliance Status

Get compliance overview for all team members.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/manager/team-compliance`

**Success Response (200):**
```json
{
  "success": true,
  "compliance": {
    "totalTeamMembers": 10,
    "compliantMembers": 8,
    "membersWithViolations": 2,
    "totalViolations": 5,
    "criticalViolations": 1,
    "violationsByType": {
      "missing_break": 3,
      "daily_hours_limit_exceeded": 2
    }
  }
}
```

---

### Get Team Hours Summary

Get working hours summary for team.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/manager/team-hours`

**Query Parameters:**
- `period` (string, optional, default: `today`) - Period: `today`, `week`, `month`

**Success Response (200):**
```json
{
  "success": true,
  "summary": {
    "period": "today",
    "totalHours": 80.5,
    "averageHoursPerPerson": 8.05,
    "teamSize": 10,
    "membersClockedIn": 8
  }
}
```

---

### Approve Time Entry Correction (Manager)

Approve a time entry correction request (manager only).

**Endpoint:** `POST /apps/arbeitszeitcheck/api/manager/time-entries/{timeEntryId}/approve-correction`

**Path Parameters:**
- `timeEntryId` (integer, required) - Time entry ID

**Request Body:**
```json
{
  "comment": "Approved"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "entry": {
    "id": 1,
    "status": "completed",
    "justification": null
  },
  "message": "Time entry correction approved"
}
```

---

### Reject Time Entry Correction (Manager)

Reject a time entry correction request (manager only).

**Endpoint:** `POST /apps/arbeitszeitcheck/api/manager/time-entries/{timeEntryId}/reject-correction`

**Path Parameters:**
- `timeEntryId` (integer, required) - Time entry ID

**Request Body:**
```json
{
  "reason": "Insufficient justification"
}
```

**Success Response (200):**
```json
{
  "success": true,
  "entry": {
    "id": 1,
    "status": "rejected",
    "justification": "Insufficient justification"
  }
}
```

---

## Compliance API

### Get Violations

Get compliance violations for the current user (or filtered by user for admins).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/compliance/violations`

**Query Parameters:**
- `userId` (string, optional) - Filter by user ID (admin only)
- `violationType` (string, optional) - Filter by type
- `resolved` (boolean, optional) - Filter by resolved status
- `severity` (string, optional) - Filter by severity: `info`, `warning`, `error`
- `startDate` (string, optional) - Start date filter (Y-m-d)
- `endDate` (string, optional) - End date filter (Y-m-d)
- `limit` (integer, optional, default: 25)
- `offset` (integer, optional, default: 0)

**Success Response (200):**
```json
{
  "success": true,
  "violations": [
    {
      "id": 1,
      "userId": "user123",
      "violationType": "missing_break",
      "description": "Missing 30-minute break after 6 hours",
      "date": "2024-01-15",
      "severity": "warning",
      "resolved": false,
      "timeEntryId": 1,
      "createdAt": "2024-01-15T15:00:00Z"
    }
  ],
  "total": 1
}
```

**Violation Types:**
- `insufficient_rest_period` - Less than 11 hours between shifts
- `daily_hours_limit_exceeded` - Exceeded daily maximum (8/10 hours)
- `weekly_hours_limit_exceeded` - Exceeded weekly average (48 hours)
- `missing_break` - Missing 30-minute break after 6 hours
- `excessive_working_hours` - Working hours exceed limits
- `night_work` - Work between 11 PM and 6 AM
- `sunday_work` - Work on Sunday
- `holiday_work` - Work on public holiday

---

### Get Violation

Get a single violation by ID.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/compliance/violations/{id}`

**Path Parameters:**
- `id` (integer, required) - Violation ID

**Success Response (200):**
```json
{
  "success": true,
  "violation": {
    "id": 1,
    "violationType": "missing_break",
    "description": "Missing 30-minute break after 6 hours",
    "date": "2024-01-15",
    "severity": "warning",
    "resolved": false
  }
}
```

---

### Resolve Violation

Mark a violation as resolved.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/compliance/violations/{id}/resolve`

**Path Parameters:**
- `id` (integer, required) - Violation ID

**Success Response (200):**
```json
{
  "success": true,
  "violation": {
    "id": 1,
    "resolved": true,
    "resolvedAt": "2024-01-16T10:00:00Z"
  }
}
```

---

### Get Compliance Status

Get overall compliance status for the current user.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/compliance/status`

**Success Response (200):**
```json
{
  "success": true,
  "status": {
    "compliant": false,
    "violationCount": 2,
    "criticalViolations": 1,
    "warnings": 1,
    "lastCheck": "2024-01-15T23:59:59Z"
  }
}
```

---

### Get Compliance Report

Generate a compliance report for a date range.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/compliance/report`

**Query Parameters:**
- `startDate` (string, optional) - Start date (Y-m-d), default: 30 days ago
- `endDate` (string, optional) - End date (Y-m-d), default: today

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "startDate": "2024-01-01",
    "endDate": "2024-01-31",
    "totalViolations": 10,
    "violationsByType": {
      "missing_break": 5,
      "daily_hours_limit_exceeded": 3,
      "insufficient_rest_period": 2
    },
    "violationsBySeverity": {
      "error": 2,
      "warning": 8
    },
    "resolvedViolations": 8,
    "unresolvedViolations": 2
  }
}
```

---

## Reporting API

### Daily Report

Get daily time tracking report.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/reports/daily`

**Query Parameters:**
- `date` (string, optional) - Date (Y-m-d), default: today
- `userId` (string, optional) - User ID (admin only), default: current user

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "date": "2024-01-15",
    "userId": "user123",
    "totalHours": 8.5,
    "workingHours": 8.0,
    "breakHours": 0.5,
    "entries": [ ... ],
    "complianceStatus": "compliant"
  }
}
```

---

### Weekly Report

Get weekly time tracking report.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/reports/weekly`

**Query Parameters:**
- `weekStart` (string, optional) - Week start date (Y-m-d), default: current week
- `userId` (string, optional) - User ID (admin only)

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "weekStart": "2024-01-15",
    "weekEnd": "2024-01-21",
    "totalHours": 40.0,
    "averageHoursPerDay": 8.0,
    "overtimeHours": 0.0,
    "days": [ ... ]
  }
}
```

---

### Monthly Report

Get monthly time tracking report.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/reports/monthly`

**Query Parameters:**
- `month` (string, optional) - Month in Y-m format, default: current month
- `userId` (string, optional) - User ID (admin only)

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "month": "2024-01",
    "totalHours": 160.0,
    "requiredHours": 160.0,
    "overtimeHours": 0.0,
    "workingDays": 20,
    "absences": [ ... ]
  }
}
```

---

### Overtime Report

Get overtime report for a date range.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/reports/overtime`

**Query Parameters:**
- `startDate` (string, optional) - Start date (Y-m-d)
- `endDate` (string, optional) - End date (Y-m-d)
- `userId` (string, optional) - User ID (admin only)

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "startDate": "2024-01-01",
    "endDate": "2024-01-31",
    "requiredHours": 160.0,
    "workedHours": 165.5,
    "overtimeHours": 5.5,
    "breakdown": [ ... ]
  }
}
```

---

### Absence Report

Get absence report for a date range.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/reports/absence`

**Query Parameters:**
- `startDate` (string, optional) - Start date (Y-m-d)
- `endDate` (string, optional) - End date (Y-m-d)
- `userId` (string, optional) - User ID (admin only)

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "startDate": "2024-01-01",
    "endDate": "2024-12-31",
    "totalAbsences": 25,
    "vacationDays": 20,
    "sickDays": 5,
    "absences": [ ... ]
  }
}
```

---

### Team Report

Get team report for multiple users.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/reports/team`

**Query Parameters:**
- `startDate` (string, required) - Start date (Y-m-d)
- `endDate` (string, required) - End date (Y-m-d)
- `userIds` (string, required) - Comma-separated user IDs

**Success Response (200):**
```json
{
  "success": true,
  "report": {
    "startDate": "2024-01-01",
    "endDate": "2024-01-31",
    "teamMembers": [
      {
        "userId": "user1",
        "totalHours": 160.0,
        "overtimeHours": 0.0,
        "absences": 5
      }
    ],
    "teamTotals": {
      "totalHours": 1600.0,
      "averageHoursPerPerson": 160.0
    }
  }
}
```

---

## Export API

### Export Time Entries

Export time entries in various formats.

**Endpoint:** `GET /apps/arbeitszeitcheck/export/time-entries`

**Query Parameters:**
- `format` (string, optional, default: `csv`) - Export format: `csv`, `json`, `pdf`, `datev`
- `startDate` (string, optional) - Start date (Y-m-d), default: 30 days ago
- `endDate` (string, optional) - End date (Y-m-d), default: today

**Response:** File download (Content-Type depends on format)

**Formats:**
- `csv` - CSV file with time entries
- `json` - JSON file with complete data
- `pdf` - PDF report (falls back to CSV if PDF library not available)
- `datev` - DATEV-compatible ASCII format for payroll

---

### Export Absences

Export absences in various formats.

**Endpoint:** `GET /apps/arbeitszeitcheck/export/absences`

**Query Parameters:**
- `format` (string, optional, default: `csv`) - Export format: `csv`, `json`, `pdf`
- `startDate` (string, optional) - Start date (Y-m-d), default: 1 year ago
- `endDate` (string, optional) - End date (Y-m-d), default: today

**Response:** File download

---

### Export Compliance Report

Export compliance violations report.

**Endpoint:** `GET /apps/arbeitszeitcheck/export/compliance`

**Query Parameters:**
- `format` (string, optional, default: `pdf`) - Export format: `csv`, `json`, `pdf`
- `startDate` (string, optional) - Start date (Y-m-d), default: 30 days ago
- `endDate` (string, optional) - End date (Y-m-d), default: today

**Response:** File download

---

### Get DATEV Export Configuration

Get DATEV export configuration status.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/export/datev/config`

**Success Response (200):**
```json
{
  "success": true,
  "config": {
    "configured": true,
    "beraternummer": "12345",
    "mandantennummer": "1"
  }
}
```

---

## GDPR API

### Export Personal Data (GDPR Art. 15)

Export all personal data for the current user (GDPR right to access).

**Endpoint:** `GET /apps/arbeitszeitcheck/gdpr/export`

**Response:** JSON file download containing:
- All time entries
- All absences
- User settings
- Compliance violations
- Audit logs

**Example Response Structure:**
```json
{
  "export_metadata": {
    "user_id": "user123",
    "export_date": "2024-01-15T10:00:00Z",
    "export_reason": "GDPR Article 15 - Right to access",
    "app_version": "1.0.0"
  },
  "time_entries": [ ... ],
  "absences": [ ... ],
  "user_settings": [ ... ],
  "compliance_violations": [ ... ],
  "audit_logs": [ ... ],
  "data_summary": {
    "total_time_entries": 500,
    "total_absences": 25,
    "total_settings": 5,
    "total_violations": 10,
    "total_audit_logs": 1000
  }
}
```

---

### Delete Personal Data (GDPR Art. 17)

Request deletion of personal data (respects legal retention periods).

**Endpoint:** `POST /apps/arbeitszeitcheck/gdpr/delete`

**Success Response (200):**
```json
{
  "success": true,
  "message": "Data deletion request processed. 10 time entries deleted. 490 entries retained due to legal 2-year retention requirement.",
  "deleted_entries": 10,
  "retained_entries": 490,
  "retention_period": "2 years",
  "note": "Some data must be retained for 2 years per German labor law (ArbZG) requirements. Audit logs and compliance violations are retained for legal compliance purposes."
}
```

**Note:** This endpoint respects the 2-year minimum retention period required by German labor law. Only data older than the retention period will be deleted.

---

## Admin API

### Get Admin Settings

Get global admin settings.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/admin/settings`

**Access:** Admin only

**Success Response (200):**
```json
{
  "success": true,
  "settings": {
    "autoComplianceCheck": true,
    "requireBreakJustification": true,
    "enableViolationNotifications": true,
    "maxDailyHours": 10.0,
    "minRestPeriod": 11.0,
    "germanState": "NW",
    "retentionPeriod": 2,
    "defaultWorkingHours": 8.0
  }
}
```

---

### Update Admin Settings

Update global admin settings.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/admin/settings`

**Access:** Admin only

**Request Body:**
```json
{
  "maxDailyHours": 9.5,
  "germanState": "BY",
  "enableViolationNotifications": true
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Settings updated successfully",
  "settings": {
    "maxDailyHours": "9.5",
    "germanState": "BY"
  }
}
```

---

### Get Statistics

Get system-wide statistics (admin only).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/admin/statistics`

**Success Response (200):**
```json
{
  "success": true,
  "statistics": {
    "total_users": 100,
    "active_users_today": 85,
    "unresolved_violations": 5,
    "compliance_percentage": 95.0,
    "compliant_users": 95
  }
}
```

---

### Get Users

Get list of users with their working time model assignments (admin only).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/admin/users`

**Query Parameters:**
- `search` (string, optional) - Search query
- `limit` (integer, optional, default: 50)
- `offset` (integer, optional, default: 0)

**Success Response (200):**
```json
{
  "success": true,
  "users": [
    {
      "userId": "user1",
      "displayName": "John Doe",
      "email": "john@example.com",
      "enabled": true,
      "workingTimeModel": {
        "id": 1,
        "name": "Full-time",
        "type": "full_time",
        "weeklyHours": 40.0,
        "dailyHours": 8.0
      },
      "vacationDaysPerYear": 25,
      "hasTimeEntriesToday": true
    }
  ],
  "total": 100
}
```

---

### Get User Details

Get detailed user information including working time model (admin only).

**Endpoint:** `GET /apps/arbeitszeitcheck/api/admin/users/{userId}`

**Path Parameters:**
- `userId` (string, required) - User ID

**Success Response (200):**
```json
{
  "success": true,
  "user": {
    "userId": "user1",
    "displayName": "John Doe",
    "email": "john@example.com",
    "enabled": true,
    "workingTimeModel": { ... },
    "availableWorkingTimeModels": [ ... ]
  }
}
```

---

### Update User Working Time Model

Assign or update working time model for a user (admin only).

**Endpoint:** `PUT /apps/arbeitszeitcheck/api/admin/users/{userId}/working-time-model`

**Path Parameters:**
- `userId` (string, required) - User ID

**Request Body:**
```json
{
  "workingTimeModelId": 1,
  "vacationDaysPerYear": 25,
  "startDate": "2024-01-01",
  "endDate": null
}
```

**Success Response (200):**
```json
{
  "success": true,
  "userWorkingTimeModel": {
    "userId": "user1",
    "workingTimeModelId": 1,
    "vacationDaysPerYear": 25,
    "startDate": "2024-01-01"
  }
}
```

---

### Working Time Models Management

**List Models:**
`GET /apps/arbeitszeitcheck/api/admin/working-time-models`

**Get Model:**
`GET /apps/arbeitszeitcheck/api/admin/working-time-models/{id}`

**Create Model:**
`POST /apps/arbeitszeitcheck/api/admin/working-time-models`

**Request Body:**
```json
{
  "name": "Part-time 20h",
  "description": "20 hours per week",
  "type": "part_time",
  "weeklyHours": 20.0,
  "dailyHours": 4.0,
  "isDefault": false
}
```

**Update Model:**
`PUT /apps/arbeitszeitcheck/api/admin/working-time-models/{id}`

**Delete Model:**
`DELETE /apps/arbeitszeitcheck/api/admin/working-time-models/{id}`

---

### Audit Logs

**Get Audit Logs:**
`GET /apps/arbeitszeitcheck/api/admin/audit-logs`

**Query Parameters:**
- `start_date` (string, optional) - Start date (Y-m-d)
- `end_date` (string, optional) - End date (Y-m-d)
- `user_id` (string, optional) - Filter by user ID
- `action` (string, optional) - Filter by action type
- `entity_type` (string, optional) - Filter by entity type
- `limit` (integer, optional, default: 50)
- `offset` (integer, optional, default: 0)

**Get Audit Log Statistics:**
`GET /apps/arbeitszeitcheck/api/admin/audit-logs/stats`

**Export Audit Logs:**
`GET /apps/arbeitszeitcheck/api/admin/audit-logs/export`

**Query Parameters:**
- `format` (string, optional, default: `csv`) - `csv` or `json`
- `start_date`, `end_date`, `user_id`, `action`, `entity_type` - Same filters as GET

---

## Settings API

### Get Onboarding Status

Check if user has completed onboarding tour.

**Endpoint:** `GET /apps/arbeitszeitcheck/api/settings/onboarding-completed`

**Success Response (200):**
```json
{
  "success": true,
  "completed": false
}
```

---

### Set Onboarding Completed

Mark onboarding tour as completed.

**Endpoint:** `POST /apps/arbeitszeitcheck/api/settings/onboarding-completed`

**Request Body:**
```json
{
  "completed": true
}
```

**Success Response (200):**
```json
{
  "success": true,
  "message": "Onboarding status updated"
}
```

---

## Error Handling

### Standard Error Response Format

All errors follow this structure:

```json
{
  "success": false,
  "error": "Human-readable error message",
  "code": "ERROR_CODE"
}
```

### Common Error Codes

- `AUTHENTICATION_REQUIRED` - User not authenticated
- `ACCESS_DENIED` - Insufficient permissions
- `NOT_FOUND` - Resource not found
- `VALIDATION_ERROR` - Invalid input parameters
- `ALREADY_CLOCKED_IN` - User already has active time entry
- `INSUFFICIENT_REST` - Less than 11 hours rest period
- `ENTRY_NOT_EDITABLE` - Time entry cannot be edited (e.g., already approved)
- `ENTRY_NOT_DELETABLE` - Time entry cannot be deleted (not manual entry)
- `ABSENCE_ALREADY_PROCESSED` - Absence already approved/rejected
- `OVERLAPPING_ABSENCE` - Absence dates overlap with existing request
- `VIOLATION_ALREADY_RESOLVED` - Compliance violation already resolved

### HTTP Status Code Mapping

- `400 Bad Request` - Validation errors, business logic errors
- `401 Unauthorized` - Authentication required
- `403 Forbidden` - Access denied
- `404 Not Found` - Resource not found
- `500 Internal Server Error` - Server errors

### Example Error Responses

**Authentication Error:**
```json
{
  "success": false,
  "error": "User not authenticated",
  "code": "AUTHENTICATION_REQUIRED"
}
```
HTTP Status: `401`

**Validation Error:**
```json
{
  "success": false,
  "error": "Date and hours are required",
  "code": "VALIDATION_ERROR"
}
```
HTTP Status: `400`

**Access Denied:**
```json
{
  "success": false,
  "error": "Access denied",
  "code": "ACCESS_DENIED"
}
```
HTTP Status: `403`

---

## Rate Limiting

API endpoints are subject to Nextcloud's rate limiting. Typical limits:

- **Standard endpoints**: 100 requests per minute per user
- **Export endpoints**: 10 requests per minute per user
- **Admin endpoints**: 50 requests per minute per admin

Rate limit headers are included in responses:
- `X-RateLimit-Limit` - Maximum requests allowed
- `X-RateLimit-Remaining` - Remaining requests in current window
- `X-RateLimit-Reset` - Unix timestamp when limit resets

---

## Versioning

The API uses implicit versioning. Current version is **v1**.

Future versions will be accessible via:
- `/apps/arbeitszeitcheck/api/v2/...`

Breaking changes will be introduced in new versions only.

---

## Support

For API support and questions:
- **GitHub Issues**: https://github.com/nextcloud/arbeitszeitcheck/issues
- **Documentation**: See `docs/` directory in the app repository

---

**Last Updated:** 2025-12-29  
**API Version:** 1.0.0
