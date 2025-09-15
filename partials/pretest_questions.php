<?php /* Pre-Test (10 scored: q1..q10). Keep elig_18, elig_consent. */ ?>

<div class="rs-section">
  <h4>Eligibility</h4>
  <p>Are you aged 18 years or older?</p>
  <label><input type="radio" name="elig_18" value="yes"> Yes, I am 18 or older</label>
  <label><input type="radio" name="elig_18" value="no"> No, I am under 18</label>
</div>

<div class="rs-section">
  <h4>Consent</h4>
  <p>Do you give consent to participate in this research?</p>
  <label><input type="radio" name="elig_consent" value="yes"> I give consent to participate</label>
  <label><input type="radio" name="elig_consent" value="no"> I do not consent</label>
</div>

<!-- ===== Scored q1..q10 (a bit tricky) ===== -->

<div class="rs-section">
  <h4>1) What is phishing?</h4>
  <label><input type="radio" name="q1" value="a"> Hacking into websites directly</label>
  <label><input type="radio" name="q1" value="b"> Stealing personal info using fake messages/websites</label>
  <label><input type="radio" name="q1" value="c"> A type of antivirus software</label>
  <label><input type="radio" name="q1" value="d"> I don’t know</label>
</div>

<div class="rs-section">
  <h4>2) Which channels are commonly used for phishing? (Select all that apply)</h4>
  <label><input type="checkbox" name="q2[]" value="email"> Email</label>
  <label><input type="checkbox" name="q2[]" value="sms"> SMS</label>
  <label><input type="checkbox" name="q2[]" value="phone"> Phone calls</label>
  <label><input type="checkbox" name="q2[]" value="social"> Social media messages</label>
</div>

<div class="rs-section">
  <h4>3) A common sign of a phishing message is…</h4>
  <label><input type="radio" name="q3" value="a"> Personalised greeting and your correct name</label>
  <label><input type="radio" name="q3" value="b"> Urgent/scare tone and suspicious links</label>
  <label><input type="radio" name="q3" value="c"> Sent from a colleague</label>
  <label><input type="radio" name="q3" value="d"> Perfect grammar</label>
</div>

<div class="rs-section">
  <h4>4) Which is the legitimate Facebook domain?</h4>
  <label><input type="radio" name="q4" value="a"> facebok.com</label>
  <label><input type="radio" name="q4" value="b"> facebook.com.loginpage.co</label>
  <label><input type="radio" name="q4" value="c"> www.facebook.com</label>
  <label><input type="radio" name="q4" value="d"> login-facebook.com</label>
</div>

<div class="rs-section">
  <h4>5) Which link is most likely phishing?</h4>
  <label><input type="radio" name="q5" value="a"> www.paypal.com</label>
  <label><input type="radio" name="q5" value="b"> www.paypa1.com</label>
  <label><input type="radio" name="q5" value="c"> support.google.com</label>
  <label><input type="radio" name="q5" value="d"> amazon.in</label>
</div>

<div class="rs-section">
  <h4>6) “You’ve won $1000! Claim here: http://win-prize-now.xyz”. What should you do?</h4>
  <label><input type="radio" name="q6" value="a"> Click the link</label>
  <label><input type="radio" name="q6" value="b"> Report/mark as spam</label>
  <label><input type="radio" name="q6" value="c"> Share with friends</label>
  <label><input type="radio" name="q6" value="d"> Ignore but keep the message</label>
</div>

<div class="rs-section">
  <h4>7) Is it ever safe to share an OTP (one-time password)?</h4>
  <label><input type="radio" name="q7" value="a"> Yes, with bank staff on the phone</label>
  <label><input type="radio" name="q7" value="b"> No, never share an OTP</label>
  <label><input type="radio" name="q7" value="c"> Only with family</label>
  <label><input type="radio" name="q7" value="d"> Only by SMS</label>
</div>

<div class="rs-section">
  <h4>8) You see a shortened link like bit.ly/paypal-security. What’s safest?</h4>
  <label><input type="radio" name="q8" value="a"> Click it quickly before it expires</label>
  <label><input type="radio" name="q8" value="b"> Don’t click; go to the official app/site directly</label>
  <label><input type="radio" name="q8" value="c"> Forward to colleagues to check</label>
  <label><input type="radio" name="q8" value="d"> Disable antivirus first</label>
</div>

<div class="rs-section">
  <h4>9) Suspicious email about account security—best way to verify?</h4>
  <label><input type="radio" name="q9" value="a"> Use the link in the email</label>
  <label><input type="radio" name="q9" value="b"> Open the official website/app (not the email link)</label>
  <label><input type="radio" name="q9" value="c"> Social media comments</label>
  <label><input type="radio" name="q9" value="d"> A search engine ad</label>
</div>

<div class="rs-section">
  <h4>10) HR SMS says “Reply HELP to fix payroll issue.” No link. Safest action?</h4>
  <label><input type="radio" name="q10" value="a"> Reply HELP</label>
  <label><input type="radio" name="q10" value="b"> Click any link you find online</label>
  <label><input type="radio" name="q10" value="c"> Ignore permanently</label>
  <label><input type="radio" name="q10" value="d"> Contact HR via official intranet/phone directory</label>
</div>

<!-- Keep legacy names harmless -->
<input type="hidden" name="q11" value="">
<input type="hidden" name="q12" value="">
<input type="hidden" name="q13" value="">
<input type="hidden" name="q14" value="">
<input type="hidden" name="q15" value="">
