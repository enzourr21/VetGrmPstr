<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;
use App\Models\Units;

final class HomeController extends Controller
{
    public function index(): void
    {
        $staffs = $this->fetchPublicStaff();
        $reviews = $this->fetchReviews(3);

        $this->render('home', [
            'title' => 'Unified Pet Care & Clinic Management',
            'staffs' => $staffs,
            'reviews' => $reviews,
        ]);
    }

    public function search(): void
    {
        $q = isset($_GET['q']) && is_string($_GET['q']) ? trim($_GET['q']) : '';
        $products = [];
        $groomingServices = [];
        $vetServices = [];

        if ($q !== '') {
            $like = '%' . $q . '%';

            $pStmt = $this->db->prepare(
                "SELECT bp.business_product_id, bp.business_unit_id, bp.selling_price,
                        bu.business_unit_name, p.product_name, p.description, p.product_category,
                        GREATEST(COALESCE(i.on_hand,0)-COALESCE(i.committed,0),0) AS available
                 FROM business_product bp
                 JOIN product p ON p.product_id = bp.product_id
                 JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
                 LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
                 WHERE bp.product_status='active' AND bu.business_unit_status='active'
                   AND (p.product_name LIKE ? OR p.description LIKE ? OR p.product_category LIKE ? OR bu.business_unit_name LIKE ?)
                 ORDER BY bu.business_unit_id, p.product_name"
            );
            $pStmt->execute([$like, $like, $like, $like]);
            $products = $pStmt->fetchAll();

            $sStmt = $this->db->prepare(
                "SELECT service_id, service_name, service_category, description, base_price, estimated_duration_minutes
                 FROM service
                 WHERE service_status='active'
                   AND (service_name LIKE ? OR description LIKE ? OR service_category LIKE ?)
                 ORDER BY service_name"
            );
            $sStmt->execute([$like, $like, $like]);
            foreach ($sStmt->fetchAll() as $row) {
                if ($row['service_category'] === 'grooming') {
                    $groomingServices[] = $row;
                } else {
                    $vetServices[] = $row;
                }
            }
        }

        $this->render('public/search', [
            'title' => $q !== '' ? 'Search: ' . $q : 'Search Catalog & Services',
            'q' => $q,
            'products' => $products,
            'groomingServices' => $groomingServices,
            'vetServices' => $vetServices,
        ]);
    }

    public function services(): void
    {
        $groomingServices = $this->db->query(
            "SELECT service_id, service_name, description, base_price, estimated_duration_minutes
             FROM service WHERE service_category='grooming' AND service_status='active' ORDER BY service_name"
        )->fetchAll();

        $vetServices = $this->db->query(
            "SELECT service_id, service_name, description, base_price, estimated_duration_minutes
             FROM service WHERE service_category='medical' AND service_status='active' ORDER BY service_name"
        )->fetchAll();

        $this->render('public/services', [
            'title' => 'Veterinary & Grooming Services',
            'groomingServices' => $groomingServices,
            'vetServices' => $vetServices,
        ]);
    }

    public function staffs(): void
    {
        $this->render('public/staffs', [
            'title' => 'Our Team & Business Managers',
            'staffs' => $this->fetchPublicStaff(),
        ]);
    }

    public function reviews(): void
    {
        $this->render('public/reviews', [
            'title' => 'Customer Reviews',
            'reviews' => $this->fetchReviews(50),
            'units' => Units::all(),
            'errors' => [],
            'old' => [],
        ]);
    }

    public function storeReview(): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();

        $rating = (int)$this->post('rating', '5');
        $unitIdRaw = $this->post('business_unit_id');
        $unitId = ($unitIdRaw !== '' && ctype_digit($unitIdRaw)) ? (int)$unitIdRaw : null;
        $title = $this->post('review_title');
        $comment = $this->post('comment');

        $errors = [];
        if ($rating < 1 || $rating > 5) {
            $errors['rating'] = 'Choose a rating between 1 and 5 stars.';
        }
        if ($unitId !== null && Units::find($unitId) === null) {
            $errors['business_unit_id'] = 'Choose a valid business unit.';
        }
        if ($title === '' || mb_strlen($title) > 150) {
            $errors['review_title'] = 'Enter a short headline for your review (max 150 characters).';
        }
        if ($comment === '' || mb_strlen($comment) < 5 || mb_strlen($comment) > 1500) {
            $errors['comment'] = 'Share a few words about your experience (5 to 1500 characters).';
        }

        if ($errors) {
            http_response_code(422);
            $this->render('public/reviews', [
                'title' => 'Customer Reviews',
                'reviews' => $this->fetchReviews(50),
                'units' => Units::all(),
                'errors' => $errors,
                'old' => [
                    'rating' => $rating,
                    'business_unit_id' => $unitId,
                    'review_title' => $title,
                    'comment' => $comment,
                ],
            ]);
            return;
        }

        $stmt = $this->db->prepare(
            'INSERT INTO customer_review (customer_id, business_unit_id, rating, review_title, comment) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$cid, $unitId, $rating, $title, $comment]);

        Session::flash('success', 'Thank you for sharing your review!');
        $this->redirect('/reviews');
    }

    /**
     * Public-safe employee directory query: exposes ONLY first_name, last_name, position,
     * public role name, and business unit name. Never exposes email, phone, or account IDs.
     */
    private function fetchPublicStaff(): array
    {
        return $this->db->query(
            "SELECT e.first_name, e.last_name, e.position,
                    GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS roles,
                    GROUP_CONCAT(DISTINCT bu.business_unit_name ORDER BY bu.business_unit_name SEPARATOR ' · ') AS units
             FROM employee e
             LEFT JOIN account_role ar ON ar.user_account_id = e.user_account_id
             LEFT JOIN role r ON r.role_id = ar.role_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = ar.business_unit_id
             WHERE e.employee_status = 'active'
             GROUP BY e.employee_id, e.first_name, e.last_name, e.position
             ORDER BY FIELD( MIN(r.role_name), 'BUSINESS_MANAGER', 'VETERINARIAN', 'NURSE', 'TECHNICIAN', 'RECEPTIONIST' ), e.last_name"
        )->fetchAll();
    }

    private function fetchReviews(int $limit): array
    {
        $limit = max(1, min(100, $limit));
        return $this->db->query(
            "SELECT cr.review_id, cr.rating, cr.review_title, cr.comment, cr.created_at,
                    c.first_name, LEFT(c.last_name, 1) AS last_initial,
                    bu.business_unit_name
             FROM customer_review cr
             JOIN customer c ON c.customer_id = cr.customer_id
             LEFT JOIN business_unit bu ON bu.business_unit_id = cr.business_unit_id
             ORDER BY cr.created_at DESC, cr.review_id DESC
             LIMIT $limit"
        )->fetchAll();
    }
}
