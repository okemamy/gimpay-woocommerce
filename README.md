# GIM Pay pour WooCommerce

Paiement par carte GIM-UEMOA, Visa et Mastercard via **GIM Pay – PayForm Plus (Lightbox)**.
Compatible checkout en blocs et checkout classique, HPOS. Devise : XOF uniquement.

## Installation

1. Copier le dossier `gimpay-woocommerce` dans `wp-content/plugins/` (ou le zipper et le téléverser).
2. Activer l'extension, puis **WooCommerce › Réglages › Paiements › GIM Pay**.
3. Renseigner les informations fournies par la banque (voir tableau ci-dessous).
4. Laisser **Mode test (UAT)** coché pour les essais, le décocher pour la production.

| Réglage | Fiche banque | Utilisation |
|---|---|---|
| Merchant ID (MID) | MID passerelle GIM Pay (fourni avec la clé) | envoyé à la Lightbox, signé |
| Terminal ID (TID) | ID terminal | envoyé à la Lightbox, signé |
| Identifiant marchand de l'acquéreur | MID côté banque | information : journal + notes d'échec |
| Outlet | Outlet | information : journal + notes d'échec |
| Clé secrète du marchand | Clé sécurisée du marchand | signatures HMAC, jamais réaffichée |
| URL du script Lightbox | ex. `https://checkout.gimpay.org/js/lightbox.js` | vide = URL par défaut test/prod |
| URL du Data Service | `…/FilterTransactions` | vide = URL par défaut test/prod |

Les URL ne sont à remplir que si GIM Pay ou la banque en communique une différente ;
seules les URL HTTPS sont acceptées.

La clé secrète n'est jamais réaffichée dans l'admin (laisser le champ vide conserve
la clé enregistrée). Pour ne pas la stocker en base, la définir dans `wp-config.php` :

```php
define( 'GIMPAY_MERCHANT_ID', '...' );
define( 'GIMPAY_TERMINAL_ID', '...' );
define( 'GIMPAY_SECRET_KEY', '...' );
```

Les constantes sont prioritaires sur les réglages. Supprimer l'extension efface ses réglages.

## Fonctionnement

1. Le client choisit GIM Pay et valide : la commande est créée en « En attente de paiement »
   et il est redirigé vers la page *Payer la commande*.
2. Le serveur signe la requête (HMAC SHA-256, Annexe B 5.1) avec une référence unique
   `WC<id>T<timestamp>` et ouvre la Lightbox GIM Pay.
3. À la fin du paiement, les données signées par GIM Pay sont renvoyées à `?wc-api=gimpay`.
   Le serveur vérifie :
   - le `SecureHash` (Annexe B 5.2) avec la clé secrète,
   - que la référence appartient bien à la commande,
   - que le montant correspond,
   - (option « Double vérification », activée par défaut) que la transaction est
     « Approved » dans le Data Service `FilterTransactions`.
4. Tout est OK → commande payée. Doute (montant différent, Data Service injoignable ou
   transaction introuvable) → « En attente » avec une note explicative.

Si le client ferme la page avant la confirmation, l'action de commande
**« Vérifier le paiement GIM Pay »** (écran de la commande, admin) interroge le
Data Service et valide la commande si un paiement approuvé est trouvé.

## Montant

Le XOF n'a pas de centimes : 1 000 XOF est envoyé comme `1000` (réglage « Unité du
montant » = × 1). Vérifié dans la Lightbox UAT, qui affiche « XOF 1,000 ».

## Sécurité

- Signatures HMAC calculées côté serveur ; la clé ne quitte jamais le serveur.
- Endpoint de retour protégé par nonce (403 si invalide) + clé de commande + SecureHash
  sur une liste de champs fixe + contrôle de référence et de montant + Data Service.
- Action admin protégée par `current_user_can( 'edit_shop_orders' )` ; hooks admin
  chargés uniquement si `is_admin()`.
- Entrées nettoyées (`sanitize_*`), sorties échappées (`esc_*`), aucune requête SQL directe.

## Hooks

| Hook | Type | Usage |
|---|---|---|
| `gimpay_wc_icon` | filtre | URL de l'icône du moyen de paiement (défaut : logo GIM-UEMOA, chaîne vide pour le masquer) |
| `gimpay_wc_lightbox_config` | filtre | Options supplémentaires de la Lightbox (couleurs, `AdditionalCustomerData`…) ; les champs signés ne peuvent pas être modifiés |
| `gimpay_wc_payment_completed` | action | `( $order, $reference, $transaction_id )` après validation d'un paiement |

## Traductions

Chaînes sources en anglais, text domain `gimpay-woocommerce`, traduction française
fournie dans `languages/` (`.po`, `.mo`, `.l10n.php`). Régénérer le modèle :
`wp i18n make-pot . languages/gimpay-woocommerce.pot --exclude=tests`.

## Journal

Avec « Journal de débogage » coché : WooCommerce › État › Journaux, source `gimpay`.
La clé secrète et le SecureHash ne sont jamais journalisés.

## Tests

```sh
php tests/test-hash.php   # depuis la racine du projet (hors plugin) : 4 exemples de l'Annexe B
```

---

Réalisé par [Okemamy](https://okemamy.com).

## Développement

```sh
php tests/test-hash.php          # vérifie les signatures avec les exemples du guide
git archive --format=zip --prefix=gimpay-woocommerce/ -o ../gimpay-woocommerce.zip HEAD
```

Le zip produit exclut `tests/` et les fichiers git (voir `.gitattributes`).
