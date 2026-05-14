# DTS JSON Database Structure

## Overview
The Document Tracking System uses a JSON-based database for simplified data storage without the complexity of SQL databases.

## Database Location
```
database/json/
├── transactions.json
├── applicationLetters.json
├── issuances.json
├── users.json
├── offices.json
├── status.json
└── settings.json
```

## Table Schema

### Transactions
Tracks internal and external document transactions.

```json
{
  "id": 1,
  "type": "internal|external",
  "title": "Transaction title",
  "description": "Detailed description",
  "status": "pending|received|approved|rejected|in_progress|completed",
  "fromOffice": 1,
  "toOffice": 2,
  "createdBy": 1,
  "receivedBy": null,
  "createdAt": "2026-04-10T10:30:00Z",
  "receivedAt": null,
  "priority": "normal|high|low",
  "remarks": "Additional notes"
}
```

**Key Features:**
- Support for internal (office-to-office) and external (supplier/external source) transactions
- Status tracking from creation to completion
- Priority levels for organization
- Timestamps for audit trail

### Application Letters
Handles leave applications, equipment requests, overtime, etc.

```json
{
  "id": 1,
  "title": "Leave Application",
  "applicant": 1,
  "appliedAt": "2026-04-10T10:00:00Z",
  "status": "pending|approved|rejected",
  "type": "vacation|equipment|overtime|other",
  "duration": "5 days",
  "startDate": "2026-04-15",
  "endDate": "2026-04-19",
  "approvedBy": 1,
  "approvedAt": "2026-04-12T09:00:00Z",
  "remarks": "Approval notes"
}
```

**Key Features:**
- Multiple application types
- Approval workflow
- Date range tracking
- Approval audit trail

### Issuances
Tracks items issued to users (ID badges, keys, equipment, etc.)

```json
{
  "id": 1,
  "title": "Office Keys",
  "recipient": 1,
  "itemDescription": "Main office keys",
  "issuedAt": "2026-04-10T08:30:00Z",
  "status": "active|inactive|returned",
  "quantity": 2,
  "remarks": "Keys for rooms 101 and 102"
}
```

**Key Features:**
- Track what's issued and to whom
- Multiple quantities
- Detailed tracking information

### Users
System users and their roles.

```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "officeId": 1,
  "role": "admin|officer|staff",
  "status": "active|inactive",
  "createdAt": "2026-01-01T00:00:00Z"
}
```

### Offices
Office locations and departments.

```json
{
  "id": 1,
  "name": "Main Office",
  "description": "Administrative center",
  "location": "Building A, Floor 1",
  "status": "active|inactive"
}
```

### Statuses
Available status options with color coding.

```json
{
  "id": 1,
  "name": "pending",
  "label": "Pending",
  "color": "#FFC107",
  "description": "Awaiting action"
}
```

### Settings
Application configuration.

```json
{
  "appName": "Document Tracking System",
  "appVersion": "1.0.0",
  "schoolName": "Default School",
  "timezone": "Asia/Manila",
  "dateFormat": "YYYY-MM-DD",
  "timeFormat": "HH:mm:ss",
  "itemsPerPage": 10,
  "maxUploadSize": 10485760,
  "enableNotifications": true,
  "maintenanceMode": false,
  "createdAt": "2026-01-01T00:00:00Z",
  "updatedAt": "2026-04-12T00:00:00Z"
}
```

## Using JsonDatabase Class

### Basic CRUD Operations

```php
use App\JsonDatabase;

$db = new JsonDatabase();

// Create
$transaction = $db->create('transactions', [
    'type' => 'internal',
    'title' => 'New Request',
    'status' => 'pending',
    // ... other fields
]);

// Read
$transaction = $db->getById('transactions', 1);
$all = $db->getAll('transactions');

// Update
$updated = $db->update('transactions', 1, ['status' => 'received']);

// Delete
$db->delete('transactions', 1);
```

### Search Operations

```php
// Search by field
$pending = $db->search('transactions', 'status', 'pending');

// Filter by multiple conditions
$internalPending = $db->filter('transactions', [
    'type' => 'internal',
    'status' => 'pending'
]);

// Keyword search
$results = $db->searchByKeyword('transactions', 'Purchase', ['title', 'description']);

// Pagination
$page1 = $db->paginate('transactions', 1, 10);
```

## Features

- **Simple JSON Format**: Easy to read and understand
- **No Complex Queries**: Straightforward PHP methods
- **Built-in Search**: Keyword and filter search
- **Pagination**: Easy pagination for list views
- **Settings Management**: Global application settings
- **No Dependencies**: Works with vanilla PHP

## Performance Considerations

- Ideal for small to medium datasets
- For large datasets (10,000+ records), consider upgrading to SQLite or MySQL
- All data loaded into memory for each operation
- Good for prototyping and development

## Migration Path

If you need to scale beyond JSON, you can:
1. Keep the JsonDatabase interface
2. Create a sqliteDatabase or MySQLDatabase class with the same methods
3. No frontend code changes needed

---

**Created**: 2026-04-12
**Version**: 1.0
