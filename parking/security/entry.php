<?php
/**
 * Vehicle Entry Portal (Unified Security Workflow)
 * Loads the gate checkpoint console with manual plate lookup mode active as fallback,
 * while keeping the primary QR camera scanner accessible at all times.
 */
$defaultMode = 'manual';
$activeItem = 'entry';
require __DIR__ . '/scan.php';
