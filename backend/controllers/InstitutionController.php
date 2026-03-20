<?php
/**
 * EduCore — InstitutionController  v5.5
 *
 * GET /api/institutions/search?q=…  (public — no auth required)
 * Returns institution list for signup.html autocomplete.
 */
declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';

class InstitutionController extends BaseController
{
    public function search(): void
    {
        $q     = trim($_GET['q'] ?? '');
        $limit = min((int)($_GET['limit'] ?? 30), 100);
        $db    = $this->db();

        if ($q !== '') {
            $stmt = $db->prepare("
                SELECT institution_id, institution_name, institution_type
                FROM   institutions
                WHERE  institution_name LIKE ?
                ORDER  BY institution_name
                LIMIT  ?
            ");
            $stmt->bindValue(1, '%' . $q . '%');
            $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        } else {
            $stmt = $db->prepare("
                SELECT institution_id, institution_name, institution_type
                FROM   institutions
                ORDER  BY institution_name
                LIMIT  ?
            ");
            $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        }

        $stmt->execute();
        $this->json(['institutions' => $stmt->fetchAll()]);
    }
}
