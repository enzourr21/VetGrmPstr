<?php
declare(strict_types=1);

/**
 * Veterinary Clinic Inventory Implementation & FEFO Verification Suite
 * Usage: tools\php83\php.exe tests\vetclinic_inventory_tests.php
 */

require_once __DIR__ . '/../app/bootstrap.php';
restore_exception_handler();

use App\Core\Database;
use App\Models\InventoryBatch;
use App\Models\Units;

$pdo = Database::pdo();
$base = getenv('APP_TEST_URL') ?: 'http://127.0.0.1:8080';
$passed = 0;
$failed = 0;

function assertCheck(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS: $label\n";
    } else {
        $failed++;
        echo "  FAIL: $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

final class VetInventoryHttpClient
{
    public array $cookies = [];
    public function __construct(private string $base) {}

    public function get(string $path): array
    {
        return $this->send('GET', $path);
    }

    public function post(string $path, array $data): array
    {
        return $this->send('POST', $path, http_build_query($data));
    }

    public function csrf(string $html): string
    {
        if (preg_match('/name="csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }
        return '';
    }

    private function send(string $method, string $path, ?string $body = null): array
    {
        $headers = ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'];
        if ($this->cookies) {
            $pairs = [];
            foreach ($this->cookies as $k => $v) {
                $pairs[] = "$k=$v";
            }
            $headers[] = 'Cookie: ' . implode('; ', $pairs);
        }
        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'follow_location' => 0,
                'ignore_errors' => true,
                'timeout' => 15,
            ],
        ]);
        $raw = (string)@file_get_contents($this->base . $path, false, $ctx);
        $status = 0;
        $location = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $hdr, $m)) {
                    $status = (int)$m[1];
                } elseif (stripos($hdr, 'Location:') === 0) {
                    $location = trim(substr($hdr, 9));
                } elseif (stripos($hdr, 'Set-Cookie:') === 0) {
                    $c = trim(substr($hdr, 11));
                    $parts = explode(';', $c);
                    if ($parts && str_contains($parts[0], '=')) {
                        [$k, $v] = explode('=', $parts[0], 2);
                        $this->cookies[trim($k)] = trim($v);
                    }
                }
            }
        }
        return ['status' => $status, 'location' => $location, 'body' => $raw];
    }
}

$client = new VetInventoryHttpClient($base);

echo "====================================================================\n";
echo "VETERINARY CLINIC INVENTORY & FEFO VERIFICATION SUITE\n";
echo "====================================================================\n\n";

// 1. Authentication
$loginPage = $client->get('/staff/login');
$token = $client->csrf($loginPage['body']);
$postRes = $client->post('/staff/login', [
    'csrf_token' => $token,
    'email' => 'claire.dubois@staff.sample.vetgrpet',
    'password' => 'Staff123!',
]);
assertCheck("Claire Dubois (Vet Clinic BM) logs in via /staff/login", $postRes['status'] === 302, "status={$postRes['status']}");

// 2. Initial Inventory Page inspection
$invStockRes = $client->get('/manager/inventory?tab=stock');
assertCheck("Vet Clinic Inventory page loads HTTP 200", $invStockRes['status'] === 200);
assertCheck("Inventory page contains Overview card", str_contains($invStockRes['body'], 'id="vet-inventory-overview-card"'));
assertCheck("Overview contains Total Products stat card", str_contains($invStockRes['body'], 'Total Products'));
assertCheck("Overview contains Physical Stock stat card", str_contains($invStockRes['body'], 'Physical Stock'));
assertCheck("Overview contains Low Stock stat card", str_contains($invStockRes['body'], 'Low Stock'));
assertCheck("Overview contains Out of Stock stat card", str_contains($invStockRes['body'], 'Out of Stock'));
assertCheck("Overview contains Expiring Soon stat card", str_contains($invStockRes['body'], 'Expiring Soon'));
assertCheck("Overview contains Expired Batches stat card", str_contains($invStockRes['body'], 'Expired Batches'));
assertCheck("Overview contains Recent Inventory Movements", str_contains($invStockRes['body'], 'Recent Inventory Movements'));

// Sub-Tabs check
assertCheck("Sub-tabs contain Stock Levels & Adjustments", str_contains($invStockRes['body'], 'Stock Levels &amp; Adjustments'));
assertCheck("Sub-tabs contain Purchase Orders", str_contains($invStockRes['body'], 'Purchase Orders'));
assertCheck("Sub-tabs contain Movement History", str_contains($invStockRes['body'], 'Movement History'));

// Initial empty state check
assertCheck("Vet Clinic Inventory empty state handled gracefully", str_contains($invStockRes['body'], 'No inventory items found') || str_contains($invStockRes['body'], 'Physical On Hand'));

echo "\n--- Unit Testing Pure FEFO Stock Allocation Logic ---\n";

// Fixture: Temporary Product in Unit 2
$vetUnitId = Units::vetClinicId();
$testSku = 'VET-TEST-' . substr(bin2hex(random_bytes(3)), 0, 6);
$prodName = 'Amoxicillin Trihydrate 250mg Clavamox Test';

$pdo->beginTransaction();
try {
    $insP = $pdo->prepare("INSERT INTO product (sku, product_name, product_category, description) VALUES (?, ?, 'Medication', 'Test description')");
    $insP->execute([$testSku, $prodName]);
    $testProdId = (int)$pdo->lastInsertId();

    $insBp = $pdo->prepare("INSERT INTO business_product (business_unit_id, product_id, selling_price, product_status, requires_batch_tracking) VALUES (?, ?, 45.00, 'active', 1)");
    $insBp->execute([$vetUnitId, $testProdId]);
    $testBpId = (int)$pdo->lastInsertId();

    $insInv = $pdo->prepare("INSERT INTO inventory (business_product_id, on_hand, committed) VALUES (?, 60, 5)");
    $insInv->execute([$testBpId]);
    $testInvId = (int)$pdo->lastInsertId();

    $today = date('Y-m-d');
    $pastDate = date('Y-m-d', strtotime('-5 days'));
    $soonDate = date('Y-m-d', strtotime('+10 days'));
    $futureDate = date('Y-m-d', strtotime('+180 days'));

    // Batch A: Expired (10 units)
    $insB1 = $pdo->prepare("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES (?, 'BATCH-EXP-001', ?, 10)");
    $insB1->execute([$testInvId, $pastDate]);
    $batchExpId = (int)$pdo->lastInsertId();

    // Batch B: Expiring soon (20 units)
    $insB2 = $pdo->prepare("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES (?, 'BATCH-SOON-002', ?, 20)");
    $insB2->execute([$testInvId, $soonDate]);
    $batchSoonId = (int)$pdo->lastInsertId();

    // Batch C: Future (30 units)
    $insB3 = $pdo->prepare("INSERT INTO inventory_batch (inventory_id, batch_number, expiration_date, quantity) VALUES (?, 'BATCH-FUT-003', ?, 30)");
    $insB3->execute([$testInvId, $futureDate]);
    $batchFutId = (int)$pdo->lastInsertId();

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    die("Setup failed: " . $e->getMessage());
}

try {
    // Verify table renders when populated
    $populatedPage = $client->get('/manager/inventory?tab=stock');
    assertCheck("Stock table contains Batches & Expiry column", str_contains($populatedPage['body'], 'Batches &amp; Expiry'));
    assertCheck("Stock table contains Physical On Hand column", str_contains($populatedPage['body'], 'Physical On Hand'));
    assertCheck("Stock table contains Usable Available column", str_contains($populatedPage['body'], 'Usable Available'));
    assertCheck("Stock table displays product name", str_contains($populatedPage['body'], $prodName));
    assertCheck("Stock table displays batch number", str_contains($populatedPage['body'], 'BATCH-SOON-002'));
    assertCheck("Stock table displays expired badge", str_contains($populatedPage['body'], 'Expired'));
    // 3. Test calculateStockMetrics
    $metrics = InventoryBatch::calculateStockMetrics($pdo, $testBpId);
    assertCheck("Metrics on_hand is physical total (60)", $metrics['on_hand'] === 60, "got={$metrics['on_hand']}");
    assertCheck("Metrics committed is 5", $metrics['committed'] === 5, "got={$metrics['committed']}");
    assertCheck("Metrics expired_quantity is 10", $metrics['expired_quantity'] === 10, "got={$metrics['expired_quantity']}");
    assertCheck("Metrics usable_on_hand excludes expired stock (50)", $metrics['usable_on_hand'] === 50, "got={$metrics['usable_on_hand']}");
    assertCheck("Metrics available = usable_on_hand - committed (45)", $metrics['available'] === 45, "got={$metrics['available']}");

    // Batches status flags
    $batches = InventoryBatch::getBatchesForProduct($pdo, $testBpId);
    assertCheck("Found 3 batches for product", count($batches) === 3);
    assertCheck("Batch A is marked expired", !empty($batches[0]['is_expired']));
    assertCheck("Batch B is marked expiring soon", !empty($batches[1]['is_expiring_soon']));
    assertCheck("Batch C is marked active", empty($batches[2]['is_expired']) && empty($batches[2]['is_expiring_soon']));

    // 4. Test FEFO Allocation
    $pdo->beginTransaction();
    // Request 15 units: Should be drawn strictly from Batch B (earliest eligible non-expired batch)
    $res1 = InventoryBatch::allocateFefo($pdo, $testBpId, 15, 'clinical_dispense', 3, 'APPT-1001');
    assertCheck("FEFO allocated 15 units successfully", $res1['allocated_quantity'] === 15);
    assertCheck("Allocation was drawn from Batch B (BATCH-SOON-002)", count($res1['allocations']) === 1 && $res1['allocations'][0]['batch_number'] === 'BATCH-SOON-002');
    $pdo->commit();

    // Verify database state after 15 unit allocation
    $chkSoon = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchSoonId")->fetchColumn();
    $chkExp = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchExpId")->fetchColumn();
    $chkOnHand = (int)$pdo->query("SELECT on_hand FROM inventory WHERE inventory_id = $testInvId")->fetchColumn();
    assertCheck("Batch B quantity reduced from 20 to 5", $chkSoon === 5, "got=$chkSoon");
    assertCheck("Batch A (expired) was NOT touched (remains 10)", $chkExp === 10, "got=$chkExp");
    assertCheck("Aggregate on_hand reduced to 45", $chkOnHand === 45, "got=$chkOnHand");

    // Test Batch Splitting: Request 15 units (Batch B only has 5 left, so remaining 10 must be drawn from Batch C)
    $pdo->beginTransaction();
    $res2 = InventoryBatch::allocateFefo($pdo, $testBpId, 15, 'clinical_dispense', 3, 'APPT-1002');
    assertCheck("FEFO split allocation across multiple batches", count($res2['allocations']) === 2);
    assertCheck("First split allocation took remaining 5 from Batch B", $res2['allocations'][0]['batch_number'] === 'BATCH-SOON-002' && $res2['allocations'][0]['quantity'] === 5);
    assertCheck("Second split allocation took 10 from Batch C", $res2['allocations'][1]['batch_number'] === 'BATCH-FUT-003' && $res2['allocations'][1]['quantity'] === 10);
    $pdo->commit();

    // Verify database state after split allocation
    $chkSoon2 = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchSoonId")->fetchColumn();
    $chkFut2 = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchFutId")->fetchColumn();
    assertCheck("Batch B quantity is now 0", $chkSoon2 === 0, "got=$chkSoon2");
    assertCheck("Batch C quantity reduced from 30 to 20", $chkFut2 === 20, "got=$chkFut2");

    // Test Insufficient Stock: Request 100 units (only 20 eligible units left in Batch C)
    $threwExpected = false;
    $pdo->beginTransaction();
    try {
        InventoryBatch::allocateFefo($pdo, $testBpId, 100, 'clinical_dispense', 3, 'APPT-FAIL');
    } catch (\RuntimeException $re) {
        $threwExpected = true;
        $pdo->rollBack();
    }
    assertCheck("FEFO throws RuntimeException when eligible stock is insufficient", $threwExpected);

    // Verify rollback
    $chkFutAfterFail = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchFutId")->fetchColumn();
    assertCheck("Batch C quantity unchanged after failed allocation (remains 20)", $chkFutAfterFail === 20);

    echo "\n--- End-to-End Stock Adjustments via HTTP ---\n";

    // 5. Test Batch-aware Stock Decrease (Normal deduction)
    $page = $client->get('/manager/inventory?tab=stock');
    $csrf = $client->csrf($page['body']);

    $adjDecRes = $client->post('/manager/inventory/adjust', [
        'csrf_token' => $csrf,
        'business_product_id' => $testBpId,
        'adjustment_type' => 'decrease',
        'batch_id' => $batchFutId,
        'quantity' => 4,
        'reason' => 'Damaged product',
        'related_reference' => 'TICKET-DAMAGED-99',
    ]);
    assertCheck("Stock decrease redirects with 302", $adjDecRes['status'] === 302);

    $futAfterDec = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchFutId")->fetchColumn();
    assertCheck("Batch C quantity decreased from 20 to 16", $futAfterDec === 16, "got=$futAfterDec");

    // 6. Test Dedicated Reason: Expired stock write-off
    $page = $client->get('/manager/inventory?tab=stock');
    $csrf = $client->csrf($page['body']);

    $adjWriteOffRes = $client->post('/manager/inventory/adjust', [
        'csrf_token' => $csrf,
        'business_product_id' => $testBpId,
        'adjustment_type' => 'decrease',
        'batch_id' => $batchExpId,
        'quantity' => 10,
        'reason' => 'Expired stock write-off',
        'related_reference' => 'DISPOSAL-AUDIT-01',
    ]);
    assertCheck("Expired stock write-off redirects with 302", $adjWriteOffRes['status'] === 302);

    $expAfterWriteOff = (int)$pdo->query("SELECT quantity FROM inventory_batch WHERE batch_id = $batchExpId")->fetchColumn();
    assertCheck("Expired Batch A quantity reduced to 0 after write-off", $expAfterWriteOff === 0, "got=$expAfterWriteOff");

    // Verify movement logged with type 'write_off_expired'
    $chkMov = $pdo->prepare("SELECT movement_type, quantity, notes FROM inventory_movement WHERE inventory_id = ? AND movement_type = 'write_off_expired' ORDER BY movement_id DESC LIMIT 1");
    $chkMov->execute([$testInvId]);
    $writeOffMov = $chkMov->fetch();
    assertCheck("Movement history recorded 'write_off_expired' movement type", !empty($writeOffMov));
    assertCheck("Write-off movement quantity is -10", (int)($writeOffMov['quantity'] ?? 0) === -10);

    // 7. Test Batch-aware Stock Increase (Creating a new batch)
    $page = $client->get('/manager/inventory?tab=stock');
    $csrf = $client->csrf($page['body']);

    $newBatchNum = 'BATCH-NEW-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $newBatchExp = date('Y-m-d', strtotime('+365 days'));

    $adjIncRes = $client->post('/manager/inventory/adjust', [
        'csrf_token' => $csrf,
        'business_product_id' => $testBpId,
        'adjustment_type' => 'increase',
        'batch_id' => 0, // Create new batch
        'batch_number' => $newBatchNum,
        'expiration_date' => $newBatchExp,
        'quantity' => 25,
        'reason' => 'New stock received',
    ]);
    assertCheck("Stock increase redirects with 302", $adjIncRes['status'] === 302);

    $newBatchRow = $pdo->prepare("SELECT batch_id, quantity, expiration_date FROM inventory_batch WHERE inventory_id = ? AND batch_number = ?");
    $newBatchRow->execute([$testInvId, $newBatchNum]);
    $nb = $newBatchRow->fetch();
    assertCheck("New batch was successfully created in inventory_batch", !empty($nb));
    assertCheck("New batch has quantity 25", (int)($nb['quantity'] ?? 0) === 25);
    assertCheck("New batch has correct expiration date", ($nb['expiration_date'] ?? '') === $newBatchExp);

    echo "\n--- End-to-End Stock Receiving Test ---\n";

    // 8. Create PO and test Receiving with batch tracking
    $poId = 0;
    $pdo->beginTransaction();
    $poNum = 'PO-VET-' . substr(bin2hex(random_bytes(3)), 0, 6);
    $insPo = $pdo->prepare("INSERT INTO purchase_order (business_unit_id, created_by_employee_id, po_number, supplier_name, contact_person, order_date, po_status) VALUES (?, 3, ?, 'Vet Supplies Direct', 'John Doe', NOW(), 'Ordered')");
    $insPo->execute([$vetUnitId, $poNum]);
    $poId = (int)$pdo->lastInsertId();

    $insPoi = $pdo->prepare("INSERT INTO purchase_order_item (purchase_order_id, business_product_id, quantity_ordered, unit_cost) VALUES (?, ?, 50, 40.00)");
    $insPoi->execute([$poId, $testBpId]);
    $poiId = (int)$pdo->lastInsertId();
    $pdo->commit();

    $poReceivingBatch = 'BATCH-RCV-' . substr(bin2hex(random_bytes(3)), 0, 5);
    $poReceivingExp = date('Y-m-d', strtotime('+500 days'));

    $page = $client->get('/manager/inventory?tab=orders');
    $csrf = $client->csrf($page['body']);

    // Receive 40 units: 35 accepted, 5 rejected (broken vials)
    $rcvRes = $client->post("/manager/inventory/purchase-orders/$poId/receive", [
        'csrf_token' => $csrf,
        'items' => [
            0 => [
                'po_item_id' => $poiId,
                'business_product_id' => $testBpId,
                'quantity_received' => 40,
                'quantity_accepted' => 35,
                'quantity_rejected' => 5,
                'rejection_reason' => 'Broken vials during shipping',
                'batch_number' => $poReceivingBatch,
                'expiration_date' => $poReceivingExp,
            ],
        ],
        'notes' => 'Courier delivery, partial shipment received and verified.',
    ]);
    assertCheck("PO Receiving redirects with 302", $rcvRes['status'] === 302);

    // Verify receiving records
    $rcvBatchRow = $pdo->prepare("SELECT quantity, expiration_date FROM inventory_batch WHERE inventory_id = ? AND batch_number = ?");
    $rcvBatchRow->execute([$testInvId, $poReceivingBatch]);
    $rb = $rcvBatchRow->fetch();
    assertCheck("Received batch created with accepted quantity 35", (int)($rb['quantity'] ?? 0) === 35, "got=" . ($rb['quantity'] ?? 'null'));
    assertCheck("Received batch has expiration date $poReceivingExp", ($rb['expiration_date'] ?? '') === $poReceivingExp);

    // Verify PO status transitioned to 'Partially Received'
    $chkPoStatus = $pdo->query("SELECT po_status FROM purchase_order WHERE purchase_order_id = $poId")->fetchColumn();
    assertCheck("PO status is now 'Partially Received'", $chkPoStatus === 'Partially Received', "got=$chkPoStatus");

    // Verify receiving item rejection recorded
    $sriRow = $pdo->prepare("SELECT quantity_received, quantity_accepted, quantity_rejected, rejection_reason FROM stock_receiving_item WHERE business_product_id = ? AND rejection_reason LIKE '%Broken vials%' LIMIT 1");
    $sriRow->execute([$testBpId]);
    $sri = $sriRow->fetch();
    assertCheck("Rejection logged with quantity 5 and reason", (int)($sri['quantity_rejected'] ?? 0) === 5);

    // Verify receiving movement logged
    $rcvMov = $pdo->prepare("SELECT movement_type, quantity, notes FROM inventory_movement WHERE inventory_id = ? AND movement_type = 'receiving' ORDER BY movement_id DESC LIMIT 1");
    $rcvMov->execute([$testInvId]);
    $rm = $rcvMov->fetch();
    assertCheck("Receiving logged in inventory_movement with +35 quantity", (int)($rm['quantity'] ?? 0) === 35);

    echo "\n--- Movement History Tab & Isolation Tests ---\n";

    // 9. Movement History page loads
    $movPage = $client->get('/manager/inventory?tab=movements');
    assertCheck("Movement History tab loads HTTP 200", $movPage['status'] === 200);
    assertCheck("Movement History table displays receiving record", str_contains($movPage['body'], 'receiving'));
    assertCheck("Movement History table displays write-off record", str_contains($movPage['body'], 'write off expired'));
    assertCheck("Movement History displays batch number", str_contains($movPage['body'], $poReceivingBatch));

    // Movement History search
    $movSearch = $client->get('/manager/inventory?tab=movements&q=' . urlencode($poReceivingBatch));
    assertCheck("Movement History search matches batch number", str_contains($movSearch['body'], $poReceivingBatch));

    // 10. Business Unit Isolation
    $petRes = $client->get('/manager/inventory?unit_id=1');
    assertCheck("Vet Clinic BM cannot access Pet Store inventory (?unit_id=1: 403 Forbidden)", $petRes['status'] === 403);

    $grmRes = $client->get('/manager/inventory?unit_id=3');
    assertCheck("Vet Clinic BM cannot access Grooming inventory (?unit_id=3: 403 Forbidden)", $grmRes['status'] === 403);

} finally {
    // CLEANUP FIXTURES
    echo "\n--- Cleaning up test fixtures ---\n";
    $pdo->exec("DELETE FROM inventory_movement WHERE inventory_id = $testInvId");
    $pdo->exec("DELETE FROM inventory_batch WHERE inventory_id = $testInvId");
    $pdo->exec("DELETE FROM stock_receiving_item WHERE business_product_id = $testBpId");
    $pdo->exec("DELETE FROM stock_receiving WHERE business_unit_id = $vetUnitId AND receiving_reference LIKE 'RCV-$poNum%'");
    if (!empty($poId)) {
        $pdo->exec("DELETE FROM purchase_order_item WHERE purchase_order_id = $poId");
        $pdo->exec("DELETE FROM purchase_order WHERE purchase_order_id = $poId");
    }
    $pdo->exec("DELETE FROM stock_adjustment WHERE business_product_id = $testBpId");
    $pdo->exec("DELETE FROM inventory WHERE inventory_id = $testInvId");
    $pdo->exec("DELETE FROM business_product WHERE business_product_id = $testBpId");
    $pdo->exec("DELETE FROM product WHERE product_id = $testProdId");
    echo "  Cleaned up all temporary test fixtures.\n";
}

echo "\n====================================================================\n";
echo "TEST RESULTS: $passed PASSED, $failed FAILED\n";
echo "====================================================================\n";

if ($failed > 0) {
    exit(1);
}
