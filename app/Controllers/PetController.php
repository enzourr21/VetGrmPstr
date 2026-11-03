<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\CSRF;
use App\Core\Session;

final class PetController extends Controller
{
    public const SPECIES = ['Dog', 'Cat', 'Rabbit', 'Bird', 'Other'];

    public function index(): void
    {
        [, $cid] = $this->requireCustomer();
        $s = $this->db->prepare('SELECT * FROM pet WHERE customer_id=? ORDER BY pet_name');
        $s->execute([$cid]);
        $this->render('pets/index', ['title' => 'My pets', 'pets' => $s->fetchAll()]);
    }

    public function create(): void
    {
        $this->requireCustomer();
        $this->render('pets/form', ['title' => 'Add a pet', 'pet' => [], 'errors' => [], 'action' => '/pets']);
    }

    public function store(): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        [$f, $errors] = $this->validated();
        if ($errors) {
            http_response_code(422);
            $this->render('pets/form', ['title' => 'Add a pet', 'pet' => $f, 'errors' => $errors, 'action' => '/pets']);
            return;
        }
        $this->db->prepare('INSERT INTO pet (customer_id, pet_name, species, breed, sex, date_of_birth, weight_kg, color_markings, notes) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$cid, $f['pet_name'], $f['species'], $f['breed'], $f['sex'], $f['date_of_birth'], $f['weight_kg'], $f['color_markings'], $f['notes']]);
        Session::flash('success', $f['pet_name'] . ' was added.');
        $this->redirect('/pets');
    }

    public function edit(int $id): void
    {
        [, $cid] = $this->requireCustomer();
        $this->render('pets/form', ['title' => 'Edit pet', 'pet' => $this->owned($id, $cid), 'errors' => [], 'action' => "/pets/$id"]);
    }

    public function update(int $id): void
    {
        [, $cid] = $this->requireCustomer();
        CSRF::check();
        $this->owned($id, $cid); // 404 for missing or someone else's pet
        [$f, $errors] = $this->validated();
        if ($errors) {
            http_response_code(422);
            $this->render('pets/form', ['title' => 'Edit pet', 'pet' => $f, 'errors' => $errors, 'action' => "/pets/$id"]);
            return;
        }
        // ownership is enforced in the UPDATE itself as well
        $u = $this->db->prepare('UPDATE pet SET pet_name=?, species=?, breed=?, sex=?, date_of_birth=?, weight_kg=?, color_markings=?, notes=? WHERE pet_id=? AND customer_id=?');
        $u->execute([$f['pet_name'], $f['species'], $f['breed'], $f['sex'], $f['date_of_birth'], $f['weight_kg'], $f['color_markings'], $f['notes'], $id, $cid]);
        Session::flash('success', 'Pet updated.');
        $this->redirect('/pets');
    }

    private function owned(int $id, int $cid): array
    {
        $s = $this->db->prepare('SELECT * FROM pet WHERE pet_id=? AND customer_id=?');
        $s->execute([$id, $cid]);
        return $s->fetch() ?: $this->notFound();
    }

    private function validated(): array
    {
        $f = [
            'pet_name' => $this->post('pet_name'),
            'species' => $this->post('species'),
            'breed' => $this->post('breed'),
            'sex' => $this->post('sex', 'unknown'),
            'date_of_birth' => $this->post('date_of_birth'),
            'weight_kg' => $this->post('weight_kg'),
            'color_markings' => $this->post('color_markings'),
            'notes' => $this->post('notes'),
        ];
        $e = [];
        if ($f['pet_name'] === '' || mb_strlen($f['pet_name']) > 100) $e['pet_name'] = 'Enter the pet\'s name (max 100 characters).';
        if (!in_array($f['species'], self::SPECIES, true)) $e['species'] = 'Choose a species.';
        if (mb_strlen($f['breed']) > 100) $e['breed'] = 'Maximum 100 characters.';
        if (!in_array($f['sex'], ['male', 'female', 'unknown'], true)) $e['sex'] = 'Choose a valid option.';
        if ($f['date_of_birth'] !== '') {
            $d = \DateTime::createFromFormat('Y-m-d', $f['date_of_birth']);
            if (!$d || $d->format('Y-m-d') !== $f['date_of_birth'] || $d > new \DateTime('today')) $e['date_of_birth'] = 'Enter a valid past date.';
        }
        if ($f['weight_kg'] !== '' && (!is_numeric($f['weight_kg']) || (float)$f['weight_kg'] <= 0 || (float)$f['weight_kg'] > 200)) $e['weight_kg'] = 'Enter a weight between 0 and 200 kg.';
        if (mb_strlen($f['color_markings']) > 255) $e['color_markings'] = 'Maximum 255 characters.';
        if (mb_strlen($f['notes']) > 2000) $e['notes'] = 'Maximum 2000 characters.';
        $n = fn($v) => $v === '' ? null : $v;
        foreach (['breed', 'date_of_birth', 'weight_kg', 'color_markings', 'notes'] as $k) {
            $f[$k] = $n($f[$k]);
        }
        return [$f, $e];
    }
}

