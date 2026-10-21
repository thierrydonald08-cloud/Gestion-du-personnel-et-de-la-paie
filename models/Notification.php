 <?php

class Notification
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function creer(int $utilisateurId, string $type, string $titre, ?string $message, ?string $lien): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification (utilisateur_id, type, titre, message, lien, date_creation)
             VALUES (:utilisateur_id, :type, :titre, :message, :lien, NOW())'
        );
        $stmt->execute([
            ':utilisateur_id' => $utilisateurId,
            ':type'           => $type,
            ':titre'          => $titre,
            ':message'        => $message,
            ':lien'           => $lien,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function marquerEmailEnvoye(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE notification SET email_envoye = 1 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function listerPourUtilisateur(int $utilisateurId, int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, type, titre, message, lien, lue, date_creation
             FROM notification
             WHERE utilisateur_id = :id
             ORDER BY date_creation DESC, id DESC
             LIMIT :limite'
        );
        $stmt->bindValue(':id', $utilisateurId, PDO::PARAM_INT);
        $stmt->bindValue(':limite', $limite, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function compterNonLues(int $utilisateurId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM notification WHERE utilisateur_id = :id AND lue = 0');
        $stmt->execute([':id' => $utilisateurId]);
        return (int) $stmt->fetchColumn();
    }

    public function marquerLue(int $id, int $utilisateurId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notification SET lue = 1 WHERE id = :id AND utilisateur_id = :utilisateur_id'
        );
        $stmt->execute([':id' => $id, ':utilisateur_id' => $utilisateurId]);
        return $stmt->rowCount() === 1;
    }

    public function marquerToutesLues(int $utilisateurId): void
    {
        $stmt = $this->pdo->prepare('UPDATE notification SET lue = 1 WHERE utilisateur_id = :id AND lue = 0');
        $stmt->execute([':id' => $utilisateurId]);
    }
}
