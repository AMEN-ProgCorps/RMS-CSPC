<?php

/**
 * DTS - JSON Database Usage Examples
 * 
 * This file demonstrates how to use the JsonDatabase class
 * for all CRUD operations and searches
 */

require_once __DIR__ . '/JsonDatabase.php';

$db = new JsonDatabase();

// ============================================
// TRANSACTIONS
// ============================================

// Get all transactions
$allTransactions = $db->getAll('transactions');

// Get a specific transaction
$transaction = $db->getById('transactions', 1);

// Create a new transaction
$newTransaction = $db->create('transactions', [
    'type' => 'internal',
    'title' => 'New Request',
    'description' => 'Description here',
    'status' => 'pending',
    'fromOffice' => 1,
    'toOffice' => 2,
    'createdBy' => 1,
    'receivedBy' => null,
    'createdAt' => now()->toIso8601String(),
    'receivedAt' => null,
    'priority' => 'normal',
    'remarks' => ''
]);

// Update transaction
$updated = $db->update('transactions', 1, [
    'status' => 'received',
    'receivedBy' => 2,
    'receivedAt' => now()->toIso8601String()
]);

// Delete transaction
$deleted = $db->delete('transactions', 1);

// Search transactions by status
$pending = $db->search('transactions', 'status', 'pending');

// Filter by multiple conditions
$internalPending = $db->filter('transactions', [
    'type' => 'internal',
    'status' => 'pending'
]);

// Search by keyword
$results = $db->searchByKeyword('transactions', 'Purchase', ['title', 'description']);

// Paginate results
$page1 = $db->paginate('transactions', 1, 10);

// ============================================
// APPLICATION LETTERS
// ============================================

// Get all application letters
$allLetters = $db->getAll('applicationLetters');

// Get pending applications
$pendingApps = $db->search('applicationLetters', 'status', 'pending');

// Create new application letter
$newApp = $db->create('applicationLetters', [
    'title' => 'Leave Application',
    'applicant' => 1,
    'appliedAt' => now()->toIso8601String(),
    'status' => 'pending',
    'type' => 'vacation',
    'duration' => '3 days',
    'startDate' => '2026-04-20',
    'endDate' => '2026-04-22',
    'approvedBy' => null,
    'approvedAt' => null,
    'remarks' => ''
]);

// Approve an application
$approved = $db->update('applicationLetters', 1, [
    'status' => 'approved',
    'approvedBy' => 1,
    'approvedAt' => now()->toIso8601String(),
    'remarks' => 'Approved - No conflicts found'
]);

// ============================================
// ISSUANCES
// ============================================

// Get all issuances
$allIssuances = $db->getAll('issuances');

// Get issuances for a specific recipient
$userIssuances = $db->search('issuances', 'recipient', 1);

// Create new issuance
$newIssuance = $db->create('issuances', [
    'title' => 'Office Equipment',
    'recipient' => 2,
    'itemDescription' => 'Desktop Computer',
    'issuedAt' => now()->toIso8601String(),
    'status' => 'active',
    'quantity' => 1,
    'remarks' => 'Serial: DE-2026-001'
]);

// ============================================
// USERS
// ============================================

// Get all users
$allUsers = $db->getAll('users');

// Get user by ID
$user = $db->getById('users', 1);

// Search users by office
$officeStaff = $db->search('users', 'officeId', 1);

// ============================================
// OFFICES
// ============================================

// Get all offices
$allOffices = $db->getAll('offices');

// Get a specific office
$office = $db->getById('offices', 1);

// ============================================
// STATUS/STATUSES
// ============================================

// Get all available statuses
$allStatuses = $db->getAll('statuses');

// ============================================
// SETTINGS
// ============================================

// Get all settings
$settings = $db->getSettings();

// Update specific settings
$updated = $db->updateSettings([
    'appName' => 'Document Tracking System v2',
    'itemsPerPage' => 20
]);

// ============================================
// COUNTING
// ============================================

// Count total transactions
$totalTransactions = $db->count('transactions');

// Count pending applications
$pendingCount = count($db->search('applicationLetters', 'status', 'pending'));
