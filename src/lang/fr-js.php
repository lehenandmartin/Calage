<?php
// French translations for the scripts (assets/lang.js). Same rules as fr.php.
return [

    // Setup wizard (setup.js)
    'The two passwords do not match.' => 'Les deux mots de passe ne correspondent pas.',
    'Enter the sender address for emails.' => 'Indiquez l’adresse d’expédition des mails.',
    'to be set up later' => 'à régler plus tard',
    '{server}, sender {address}' => '{server}, expéditeur {address}',

    // SMTP test (smtp-test.js)
    'Sending the test email…' => 'Envoi du mail de test…',
    'The test could not be completed. Reload the page and try again.' => 'Le test n’a pas pu aboutir. Rechargez la page et réessayez.',
    'Dialogue with the SMTP server (credentials hidden)' => 'Dialogue avec le serveur SMTP (identifiants masqués)',

    // Interface (app.js)
    'Link copied.' => 'Lien copié.',

    // Degraded preview (preview.js)
    'no web fonts' => 'sans polices web',
    'no images' => 'sans images',
    'Preview: {list}' => 'Aperçu {list}',

    // MJML import (mjml-import.js)
    'The MJML compiler could not be loaded.' => 'Le compilateur MJML n’a pas pu être chargé.',
    'MJML produced no HTML.' => 'MJML n’a produit aucun HTML.',

    // MJML (mjml.js)
    '(unreadable path)' => '(chemin illisible)',
    'The MJML compiler could not be loaded (connection to the jsdelivr CDN?).' => 'Le compilateur MJML n’a pas pu être chargé (connexion au CDN jsdelivr ?).',
    'Line {line} ({tag}): {message}' => 'Ligne {line} ({tag}) : {message}',
    'Line {line}: {message}' => 'Ligne {line} : {message}',

    // Upload (upload.js)
    'The dropped files cannot be read.' => 'Impossible de lire les fichiers déposés.',
    'No HTML, MJML, image or zip file in the selection.' => 'Aucun fichier HTML, MJML, image ou zip dans la sélection.',
    '{n} file' => ['{n} fichier', '{n} fichiers'],
    'Preparing…' => 'Préparation…',
    'Uploading {file}' => 'Envoi de {file}',
    'Analyzing the newsletter…' => 'Analyse de la newsletter…',
    'The session has expired: reload the page and sign in again.' => 'La session a expiré : rechargez la page et reconnectez-vous.',
    'The server replied with an error ({status}).' => 'Le serveur a répondu par une erreur ({status}).',
    'The upload failed.' => 'L’envoi a échoué.',
    '{n} KB' => '{n} Ko',
    '{n} MB' => '{n} Mo',
    '{n}%' => '{n} %',

    // Editor (editor.js)
    'Cannot compile: {error} Nothing will be saved until the MJML compiles.' => 'Compilation impossible : {error} Rien ne sera enregistré tant que le MJML ne compile pas.',
    '{n} MJML validation warning (the compilation still succeeds):' => ['{n} avertissement de validation MJML (la compilation aboutit quand même) :', '{n} avertissements de validation MJML (la compilation aboutit quand même) :'],

    // Live preview (live.js)
    'Up to date' => 'À jour',
    'Updating…' => 'Mise à jour…',
    'Compilation error' => 'Erreur de compilation',
];
