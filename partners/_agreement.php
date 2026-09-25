<?php
$tiers = function_exists('kb_tiers') ? kb_tiers() : [];
$pf = fn($x) => rtrim(rtrim(number_format((float)$x, 1), '0'), '.');
?>
<h2 style="margin-bottom:4px">KB Sites — Affiliate Agreement</h2>
<p class="meta" style="margin-bottom:14px">Last updated: September 25, 2026</p>

<p>This Affiliate Agreement ("Agreement") is between <b>KB Sites</b> (operated by Kauã Barbosa dos Santos, "we", "us", "KB Sites") and the person who registers for the Partner Program ("you", "Affiliate"). By creating an account and checking the agreement box, you accept these terms.</p>

<h3>1. Who can join — Brazil only</h3>
<p>The Partner Program is open to <b>residents of Brazil only</b>. Commissions are paid <b>exclusively by Pix</b>, in Brazilian reais (R$), so you must provide a valid <b>Pix key</b> and your <b>CPF</b>. By joining, you confirm that you live in Brazil and that the payout details you give us are correct and your own.</p>

<h3>2. What you do</h3>
<p>You promote KB Sites' website design services using the unique referral link and code we give you. You may share your link honestly through your own channels. You may <b>not</b> spam, send unsolicited bulk email, use paid ads that bid on our brand name, impersonate KB Sites, make false claims about our services or prices, or promote through deceptive, misleading, or illegal means.</p>

<h3>3. How referrals are tracked</h3>
<p>When someone opens your referral link, a cookie stores your code in their browser for <b>30 days</b>. If they send us a request from their own KB Sites account within that window and later become a paying client, the referral is credited to you. If a client was already in contact with us, or does not carry your code at the time they reach us, the referral may not be credited. We track referrals in good faith; our records are the final say in disputes.</p>

<h3>4. Ranks (patentes) &amp; commission</h3>
<p>You earn <b>1 point</b> for each client you refer who pays KB Sites for a website. Your points set your rank, and your rank sets your commission rate — a percentage of the one-time website <b>build price only</b>. Hosting, maintenance and any monthly fees are <b>never</b> commissionable.</p>
<ul>
  <?php foreach($tiers as $k => $t): ?>
  <li><b><?=htmlspecialchars($t[0], ENT_QUOTES)?></b> — from <b><?=(int)$t[2]?> point<?= (int)$t[2]===1 ? '' : 's'?></b>: approximately <b>~<?=$pf($t[3])?>%</b>, within a disclosed range of <b><?=$t[4][0]?>%–<?=$t[4][1]?>%</b>.</li>
  <?php endforeach; ?>
</ul>
<p><b>Why the rate is approximate ("~").</b> Website prices are set in <b>US dollars</b>, but your commission is paid to you in <b>Brazilian reais by Pix</b>. Because the US dollar–real exchange rate changes from day to day, and because of conversion and transfer factors, the exact amount you receive may vary. For that reason each rank's rate is shown as an <b>approximate figure within the range above</b>, not an exact number — your actual payout may land anywhere inside that range. The rank you hold at the time a referral is paid determines the rate applied, and a commission already earned on a client who has paid is never reduced by a later change.</p>

<h3>5. When and how you get paid</h3>
<p>Commissions become payable <b>only after the referred client's payment for the website has cleared</b> — that is, KB Sites has received it in full and it is no longer pending. Until then, a referral shows as "pending" and nothing is owed. Once payable, we pay you <b>by Pix, in reais</b>, to the Pix key on your account, normally within 15 days. You are responsible for keeping your Pix key and CPF correct and working. If a client requests a refund, cancels, or charges back, any related commission is reversed and, if already paid, may be deducted from future commissions.</p>

<h3>6. You are independent — your taxes are your own</h3>
<p>You are an independent participant, <b>not an employee, partner, or agent</b> of KB Sites. You cannot sign contracts, quote custom prices, collect payments, or make promises on our behalf. Nothing here creates an employment, labour, or partnership relationship of any kind. You are <b>solely responsible for reporting and paying any taxes</b> due on the commissions you receive, in accordance with the laws of Brazil.</p>

<h3>7. Honesty &amp; disclosure</h3>
<p>Where required by law, you must clearly disclose that you earn a commission when you recommend KB Sites. Be truthful about what we offer.</p>

<h3>8. No self-referrals or fraud</h3>
<p><b>Self-referrals are prohibited.</b> You cannot earn commission on your own purchase. You may not refer yourself, a business you own, co-own or otherwise control, or a purchase you make through a second account, and you may not use fake identities or other people's details to create referrals.</p>
<p><b>One account per person.</b> Each person may have only one KB Sites account. Creating or using extra accounts — for example, to refer yourself — is not allowed.</p>
<p><b>How we check.</b> To protect the program, we may use technical signals such as device identifiers, IP addresses, account and contact details, and payment and payout details to detect self-referrals and fraud (see our <a href="/privacy.html" target="_blank" rel="noopener">Privacy Policy</a>). If a referral looks like a possible self-referral, we may hold its commission while we review it.</p>
<p><b>Consequences.</b> Commissions on self-referred, fraudulent, or bad-faith sales are <b>void</b> and are not paid — or, if already paid, may be deducted from future commissions. We may also remove you from the Partner Program and suspend the accounts involved.</p>

<h3>9. Changes &amp; ending the program</h3>
<p>We may change the ranks, the rates, or these terms going forward, with notice: we'll show the current ranks and terms here and in your partner dashboard, and if we lower a rate we'll tell you in your account or by email before the lower rate applies. Changes do not reduce commissions already earned on clients who have paid. Either side may end this Agreement at any time. Commissions already earned on clients who have paid will still be honored. We may suspend or remove an account that breaks these terms.</p>

<h3>10. No guarantee</h3>
<p>We don't guarantee any amount of clicks, referrals, sales, or earnings. The Partner Program is provided as-is.</p>

<h3>11. Governing law</h3>
<p>This Agreement is governed by the laws of Brazil, where KB Sites operates. Questions? Send us a message through the support chat in your account.</p>
