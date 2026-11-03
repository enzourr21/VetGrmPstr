<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;
use App\Models\Cart;
use App\Models\Units;

final class ShopController extends Controller
{
    public function index(): void
    {
        $cid = Session::get('customer_id');
        $counts = $cid ? Cart::countsByUnit((int)$cid) : [];
        $allProducts = $this->db->query(
            "SELECT bp.business_product_id, bp.business_unit_id, bp.selling_price,
                    bu.business_unit_name, p.product_name, p.description, p.product_category,
                    GREATEST(COALESCE(i.on_hand,0)-COALESCE(i.committed,0),0) AS available
             FROM business_product bp
             JOIN product p ON p.product_id = bp.product_id
             JOIN business_unit bu ON bu.business_unit_id = bp.business_unit_id
             LEFT JOIN inventory i ON i.business_product_id = bp.business_product_id
             WHERE bp.product_status='active' AND bu.business_unit_status='active'
             ORDER BY bu.business_unit_id, p.product_name"
        )->fetchAll();

        $this->render('shop/index', [
            'title' => 'Products & Business Catalogs',
            'units' => Units::all(),
            'counts' => $counts,
            'allProducts' => $allProducts,
        ]);
    }

    public function catalog(int $bu): void
    {
        $cid = Session::get('customer_id');
        $unit = Units::find($bu) ?? $this->notFound();
        $s = $this->db->prepare('SELECT bp.business_product_id, bp.selling_price, p.product_name, p.description, p.product_category,
                GREATEST(COALESCE(i.on_hand,0)-COALESCE(i.committed,0),0) AS available
            FROM business_product bp JOIN product p ON p.product_id=bp.product_id
            LEFT JOIN inventory i ON i.business_product_id=bp.business_product_id
            WHERE bp.business_unit_id=? AND bp.product_status="active" ORDER BY p.product_name');
        $s->execute([$bu]);
        $cartCount = $cid ? (int)(Cart::countsByUnit((int)$cid)[$bu] ?? 0) : 0;
        $this->render('shop/catalog', [
            'title' => $unit['business_unit_name'] . ' products',
            'unit' => $unit,
            'products' => $s->fetchAll(),
            'cartCount' => $cartCount,
        ]);
    }

    public function product(int $bu, int $id): void
    {
        $unit = Units::find($bu) ?? $this->notFound();
        $s = $this->db->prepare('SELECT bp.business_product_id, bp.selling_price, p.product_name, p.description, p.product_category,
                GREATEST(COALESCE(i.on_hand,0)-COALESCE(i.committed,0),0) AS available
            FROM business_product bp JOIN product p ON p.product_id=bp.product_id
            LEFT JOIN inventory i ON i.business_product_id=bp.business_product_id
            WHERE bp.business_product_id=? AND bp.business_unit_id=? AND bp.product_status="active"');
        $s->execute([$id, $bu]);
        $this->render('shop/product', [
            'title' => 'Product Details',
            'unit' => $unit,
            'p' => $s->fetch() ?: $this->notFound(),
        ]);
    }
}
