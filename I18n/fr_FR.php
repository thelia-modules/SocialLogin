<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

return [
    // BO : formulaire de configuration, une clé par fournisseur générée depuis le registre
    'Enable %provider% login' => 'Activer la connexion %provider%',
    'Client ID' => 'Client ID',
    'Client secret' => 'Client secret',
    'App ID' => 'App ID',
    'App secret' => 'App secret',
    'Service ID' => 'Service ID',
    'Team ID' => 'Team ID',
    'Key ID' => 'Key ID',
    'Private key (.p8 content)' => 'Clé privée (contenu du fichier .p8)',
    '%provider% logo (SVG)' => 'Logo %provider% (SVG)',
    'Display order' => 'Ordre d’affichage',
    'Lower numbers appear first.' => 'Les plus petits nombres s’affichent en premier.',

    // Front : boutons de connexion, page de rattachement, page de compte
    'or' => 'ou',
    'Continue with %provider%' => 'Continuer avec %provider%',
    'Link your account' => 'Associer votre compte',
    'An account already exists for %email%. Enter its password to link this sign-in method to it.' => 'Un compte existe déjà pour %email%. Saisissez son mot de passe pour lui associer ce mode de connexion.',
    'Link account' => 'Associer le compte',
    'Social connections' => 'Connexions sociales',
    'Social connection removed.' => 'Connexion sociale supprimée.',
    'You have not linked any social account yet.' => 'Vous n’avez associé aucun compte social pour le moment.',
    'Last sign-in: %date%' => 'Dernière connexion : %date%',
    'This is your only sign-in method. Set a password first.' => 'C’est votre seul moyen de connexion. Définissez d’abord un mot de passe.',
    'Disconnect' => 'Déconnecter',

    // Front : message affiché sur la page de connexion pour une issue qui n'est pas une exception
    'sociallogin.notice.account_activation_required' => 'Cette adresse est liée à une commande invité en attente. Vérifiez votre boîte mail pour le code d’activation, ou repassez commande pour en recevoir un nouveau.',

    // Clés d'erreur levées par SocialLoginException et ses sous-classes (domaine SocialLogin::DOMAIN_NAME)
    'sociallogin.error.unknown_provider' => 'Ce fournisseur de connexion n’existe pas.',
    'sociallogin.error.provider_not_configured' => 'Ce fournisseur de connexion n’est pas disponible pour le moment.',
    'sociallogin.error.invalid_state' => 'Votre tentative de connexion n’a pas pu être vérifiée. Veuillez réessayer.',
    'sociallogin.error.provider_communication' => 'Nous n’avons pas pu contacter le fournisseur de connexion. Veuillez réessayer.',
    'sociallogin.error.identity_token_verification' => 'Le fournisseur de connexion a renvoyé des informations que nous n’avons pas pu vérifier.',
    'sociallogin.error.email_not_provided' => 'Le fournisseur de connexion ne nous a pas communiqué d’adresse e-mail.',
    'sociallogin.error.email_not_verified' => 'Le fournisseur de connexion n’a pas confirmé cette adresse e-mail.',
    'sociallogin.error.pending_identity_not_found' => 'Il n’y a aucune connexion en attente à associer. Veuillez recommencer.',
    'sociallogin.error.invalid_password' => 'Ce mot de passe est incorrect.',
    'sociallogin.error.password_confirmation_required' => 'Veuillez saisir votre mot de passe pour supprimer votre dernier moyen de connexion.',
    'sociallogin.error.identity_not_owned' => 'Cette connexion sociale n’a pas été trouvée.',
    'sociallogin.error.too_many_attempts' => 'Trop de tentatives. Veuillez réessayer plus tard.',
    'sociallogin.error.unexpected' => 'Une erreur inattendue est survenue. Veuillez réessayer.',
    'sociallogin.error.identity_already_linked' => 'Ce compte social est déjà rattaché à un autre compte client.',
    'sociallogin.error.identity_orphaned' => 'Cette connexion sociale ne correspond plus à un compte existant.',

    // BO : formulaire de configuration, champ logo refusé à la soumission
    'sociallogin.error.invalid_logo_svg' => 'Ce logo n’a pas pu être lu comme une image SVG, ou il n’en restait rien une fois les scripts, styles et références externes retirés. Collez le fichier SVG officiel du fournisseur, ou laissez le champ vide pour utiliser la marque intégrée.',
    'sociallogin.error.logo_svg_too_large' => 'Ce logo est trop volumineux pour être enregistré une fois nettoyé pour l’affichage. Collez un fichier SVG plus léger, par exemple exporté sans métadonnées ni polices intégrées.',

    // BO : formulaire de configuration, ordre d’affichage refusé à la soumission
    'sociallogin.error.invalid_position' => 'Saisissez un nombre entier supérieur ou égal à 0, ou laissez le champ vide pour garder l’ordre par défaut.',
];
