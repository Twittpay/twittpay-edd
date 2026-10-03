===========================================================================
 TWITTPAY - Easy Digital Downloads gateway
===========================================================================

 WHERE IT GOES
   Extract this zip at your WordPress root - the folder that has wp-content/ and
   wp-config.php in it. One file lands in place:

     wp-content/plugins/edd-twittpay/edd-twittpay.php

   If you would rather install it from the WordPress admin, the folder
   wp-content/plugins/edd-twittpay is what you need to end up with.

 INSTALL
   1. Plugins -> Installed Plugins -> activate "EDD TwittPay".
   2. Downloads -> Settings -> Payments.
      Tick "TwittPay" in the list of enabled gateways.
   3. Scroll down to the TwittPay Settings block and fill in:


        Brand Key           from your gateway dashboard, under Brands

        USD to BDT Rate   only used when your store currency is not BDT

   4. Buy something cheap as a test.

 HOW IT WORKS
   * Choosing this gateway at checkout creates a pending EDD payment, creates the
     payment on your gateway, empties the cart and sends the customer to the
     checkout page.
   * The gateway's own server calls  /?edd-listener=twittpay . That is the
     normal way a payment gets completed.
   * The customer's return to the success page also verifies, as a fallback for
     when the gateway cannot reach your site.
   * Both verify the transaction against the API first. A hand-typed URL does
     nothing at all.
   * COMPLETED publishes the payment, and only if it is not already published -
     so the webhook and the return cannot complete the same order twice.
   * PENDING leaves the payment pending and writes a note. The customer has sent
     the money and your merchant has not approved it. The gateway calls again with
     the answer, and that call completes the payment. Do not ask the customer to
     pay twice.

 CURRENCY
   The gateway charges BDT.

   * A BDT store sends the price as it is.
   * Any other store currency is multiplied by the USD to BDT Rate, and the
     order's own amount and currency ride along in metadata - so EDD's own records
     stay in your store currency.

 WHAT TO WATCH
   * /?edd-listener=twittpay must be reachable from the internet. Your
     gateway's server calls it directly.
   * Refunds are not done through the API. Refund on the gateway side, then
     refund in EDD by hand.

 FIXES OVER THE ORIGINAL
   * The PipraPay version compared an Brand Key sent in a webhook header - and read
     $_SERVER['HTTP_MH_PIPRAPAY_API_KEY'] without checking it was set, which is a
     warning on every call. This gateway's webhook is not signed and sends no key,
     so that check would have refused every real call. It is gone - verification
     against the API does the job, because a made-up transaction id simply does
     not verify.
   * The original only ever acted on "completed". A pending payment was silently
     dropped and never picked up. Pending is now recorded as a note and settled by
     the next webhook.
   * The original had no fallback if the webhook could not reach the site. The
     customer's return now verifies too.
   * The original looked the payment up by purchase key only. This port carries
     the payment id in metadata and uses the key as a fallback.
   * The payment is now tagged with the gateway and its transaction id, so it
     shows up properly in EDD's payment history.

 CHECKED
   The PHP was checked with a lexer that balances braces only inside real PHP
   code. PHP itself was NOT run - there is no PHP binary on the machine this was
   built on, so php -l was never executed. Test it on a staging site first.
