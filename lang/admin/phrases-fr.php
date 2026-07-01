<?php

/**
 * Exact-phrase replacements for admin HTML not yet using admin_t().
 * Auto-built from lang/admin/en.php + fr.php, plus manual page strings.
 */
$en = require __DIR__ . '/en.php';
$fr = require __DIR__ . '/fr.php';
$phrases = [];

foreach ($en as $key => $english) {
    if (!is_string($english) || $english === '') {
        continue;
    }
    if (!isset($fr[$key]) || $fr[$key] === $english) {
        continue;
    }
    $phrases[$english] = $fr[$key];
}

$extra = [
    'Settings workspace' => 'Espace paramètres',
    'Choose a section below to update branding, appearance, finance, practice catalog, or AI assistant.' => 'Choisissez une section pour mettre à jour l\'image de marque, l\'apparence, les finances, le catalogue ou l\'assistant IA.',
    'Branding & Company' => 'Image de marque et entreprise',
    'Finance & Invoices' => 'Finances et factures',
    'Practice Catalog' => 'Catalogue pratique',
    'Company name, logo, and contact details.' => 'Nom de l\'entreprise, logo et coordonnées.',
    'Currency, bank accounts, and invoice defaults.' => 'Devise, comptes bancaires et factures.',
    'Services, case categories, and lawyer specializations.' => 'Services, catégories de dossiers et spécialisations.',
    'OpenAI key and model for the AI assistant.' => 'Clé OpenAI et modèle pour l\'assistant IA.',
    'All statuses' => 'Tous les statuts',
    'All priorities' => 'Toutes les priorités',
    'Active' => 'Actif',
    'Pending' => 'En attente',
    'Under Review' => 'En révision',
    'Closed' => 'Clôturé',
    'Normal' => 'Normal',
    'High' => 'Élevé',
    'Urgent' => 'Urgent',
    'New case' => 'Nouveau dossier',
    'Add client' => 'Ajouter un client',
    'My Profile' => 'Mon profil',
    'Account details' => 'Détails du compte',
    'All notifications' => 'Toutes les notifications',
    'Record payment' => 'Enregistrer un paiement',
    'Payment activity' => 'Activité des paiements',
    'Financial summary' => 'Résumé financier',
    'Court Tracking' => 'Suivi judiciaire',
    'AI Assistant' => 'Assistant IA',
    'Generate Legal Document' => 'Générer un document juridique',
    'Generate Draft' => 'Générer le brouillon',
    'Template Library' => 'Bibliothèque de modèles',
    'Upload Document' => 'Téléverser un document',
    'Browse Documents' => 'Parcourir les documents',
    'Documents Overview' => 'Aperçu des documents',
    'Save preferences' => 'Enregistrer les préférences',
    'Welcome back,' => 'Bon retour,',
    'Total Cases' => 'Total dossiers',
    'Active Cases' => 'Dossiers actifs',
    'Pending Tasks' => 'Tâches en attente',
    'In Progress' => 'En cours',
    'Recent cases' => 'Dossiers récents',
    'No cases yet.' => 'Aucun dossier pour le moment.',
    'Create your first case' => 'Créer votre premier dossier',
    'Upcoming appointments' => 'Rendez-vous à venir',
    'No upcoming appointments' => 'Aucun rendez-vous à venir',
    'Top clients' => 'Meilleurs clients',
    'Financial overview' => 'Aperçu financier',
    'Collection rate' => 'Taux de recouvrement',
    'Cases by category' => 'Dossiers par catégorie',
    'Cases by status' => 'Dossiers par statut',
    'Appointment' => 'Rendez-vous',
    'View all' => 'Tout voir',
    'Download PDF' => 'Télécharger le PDF',
    'Print' => 'Imprimer',
    'Save Appearance' => 'Enregistrer l\'apparence',
    'Theme mode' => 'Mode d\'affichage',
    'Accent color' => 'Couleur d\'accent',
    'Display language' => 'Langue d\'affichage',
    'Light' => 'Clair',
    'Dark' => 'Sombre',
    'Remove' => 'Supprimer',
    'Cancel' => 'Annuler',
    'Close' => 'Fermer',
    'Search…' => 'Rechercher…',
    'Search cases…' => 'Rechercher des dossiers…',
    'Loading…' => 'Chargement…',
    'No new notifications' => 'Aucune nouvelle notification',
    'You are all caught up.' => 'Vous êtes à jour.',
    'Mark all read' => 'Tout marquer comme lu',
    'Sign out' => 'Déconnexion',
    'Administrator' => 'Administrateur',
    'Switch to light mode' => 'Passer en mode clair',
    'Switch to dark mode' => 'Passer en mode sombre',
    'New — not yet seen' => 'Nouveau — pas encore vu',
    'Could not load notifications.' => 'Impossible de charger les notifications.',
    'Dashboard' => 'Tableau de bord',
    'Completed' => 'Terminé',
    'View' => 'Voir',
    'Edit' => 'Modifier',
    'Delete' => 'Supprimer',
    'Court dates pagination' => 'Pagination des audiences',
    'Upcoming court dates' => 'Audiences à venir',
    'Add court date' => 'Ajouter une audience',
    'Court Dates Calendar' => 'Calendrier des audiences',
    'Appointments Calendar' => 'Calendrier des rendez-vous',
    'Manage appointments' => 'Gérer les rendez-vous',
    'Edit appointment' => 'Modifier le rendez-vous',
    'Scheduled time' => 'Heure prévue',
    'Court Tracking' => 'Suivi judiciaire',
    'Schedule appointment' => 'Planifier un rendez-vous',
    'New appointment' => 'Nouveau rendez-vous',
    'Overview' => 'Aperçu',
    'Upload' => 'Téléverser',
    'Templates' => 'Modèles',
    'Generate' => 'Générer',
    'Browse' => 'Parcourir',
    'Inactive' => 'Inactif',
    'No fees' => 'Aucun frais',
    'Outstanding' => 'En souffrance',
    'Draft' => 'Brouillon',
    'Sent' => 'Envoyé',
    'Overdue' => 'En retard',
    'Scheduled' => 'Planifié',
    'Postponed' => 'Reporté',
    'Cancelled' => 'Annulé',
    'Waiting For Client' => 'En attente du client',
    'On Hold' => 'En suspens',
    'Medium' => 'Moyen',
    'Low' => 'Faible',
    'Staff' => 'Personnel',
    'Lawyer' => 'Avocat',
    'Client' => 'Client',
    'Admin' => 'Administrateur',
];

foreach ($extra as $english => $french) {
    $phrases[$english] = $french;
}

uksort($phrases, static function (string $a, string $b): int {
    return strlen($b) <=> strlen($a);
});

return $phrases;
