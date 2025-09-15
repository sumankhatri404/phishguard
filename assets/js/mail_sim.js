/* assets/js/mail_sim.js */
(function () {
  const $ = (sel, root = document) => root.querySelector(sel);

  const wrap = $('.mail-wrap');
  if (!wrap) return;

  const userId   = Number(wrap.getAttribute('data-user-id') || 0);
  const moduleId = Number(wrap.getAttribute('data-module-id') || 0);

  const replyBtn  = $('#btnReply');
  const fwdBtn    = $('#btnViewFwd');
  const replyCard = $('#replyCard');
  const fwdCard   = $('#forwardCard');
  const sendBtn   = $('#btnSend');
  const ptsEl     = $('#pointsEarned');

  // Create summary container if missing (it appears below the reply card)
  let summaryContainer = $('#friendlySummaryContainer');
  if (!summaryContainer) {
    summaryContainer = document.createElement('div');
    summaryContainer.id = 'friendlySummaryContainer';
    (replyCard?.parentNode || wrap).insertBefore(
      summaryContainer,
      (replyCard && replyCard.nextSibling) || null
    );
  }

  const selIs = $('#selIs');
  const b1    = $('#selBecause1');
  const b1w   = $('#selBecause1Why');
  const b2    = $('#selBecause2');
  const b2w   = $('#selBecause2Why');

  replyBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    if (replyCard) replyCard.hidden = false;
    if (fwdCard)   fwdCard.hidden = true;
    updateSendEnabled();
  });

  fwdBtn?.addEventListener('click', (e) => {
    e.preventDefault();
    if (fwdCard) fwdCard.hidden = !fwdCard.hidden;
  });

  function updateSendEnabled() {
    if (!sendBtn) return;
    const haveIs    = !!selIs?.value;
    const havePair1 = !!(b1?.value && b1w?.value);
    const havePair2 = !!(b2?.value && b2w?.value);
    sendBtn.disabled = !(haveIs && (havePair1 || havePair2));
  }
  [selIs, b1, b1w, b2, b2w].forEach(el => el?.addEventListener('change', updateSendEnabled));
  updateSendEnabled();

  // -------- Scoring (0..10) with effort credit --------
  function evaluate() {
    const expectedIs = 'is'; // this scenario is phishing

    const facts = new Set();
    const pairs = [
      { who: b1?.value, why: b1w?.value },
      { who: b2?.value, why: b2w?.value },
    ];
    for (const p of pairs) {
      if (!p.who || !p.why) continue;
      if (p.who === 'the_sender'   && p.why === 'is_unknown')                facts.add('sender_unknown');
      if (p.who === 'the_messages' && p.why === 'contains_suspicious_links') facts.add('msg_links');
      if (p.who === 'the_subject'  && p.why === 'has_urgent_tone')           facts.add('subject_urgent');
      if (p.who === 'the_messages' && p.why === 'has_urgent_tone')           facts.add('subject_urgent'); // allow
    }

    const hasSender = facts.has('sender_unknown');
    const hasLinks  = facts.has('msg_links');
    const hasUrgent = facts.has('subject_urgent');

    let score = 0;
    const isCorrect = (selIs?.value === expectedIs);
    if (isCorrect) score += 4;
    if (hasSender) score += 3;
    if (hasLinks)  score += 3;
    if (hasUrgent) score += 1;

    score = Math.min(10, score);

    // Effort credit (never 0 if a real attempt was made)
    const gaveAnyClue = hasSender || hasLinks || hasUrgent;
    const madeAttempt = !!(selIs?.value && ((b1?.value && b1w?.value) || (b2?.value && b2w?.value)));
    if (!isCorrect) {
      if (gaveAnyClue) score = Math.max(score, 2);
      else if (madeAttempt) score = Math.max(score, 1);
    }

    const uSentence = buildSentence(selIs?.value, b1?.value, b1w?.value, b2?.value, b2w?.value);
    const eSentence = buildSentence('is', 'the_sender', 'is_unknown', 'the_messages', 'contains_suspicious_links');

    score = Math.max(0, Math.min(10, Math.round(score)));
    return { score, userSentence: uSentence, expectedSentence: eSentence };
  }

  function whoText(v) {
    if (v === 'the_sender')   return 'the sender';
    if (v === 'the_messages') return "the message's";
    if (v === 'the_subject')  return 'the subject';
    return "the message's";
  }
  function whyText(v) {
    if (v === 'is_unknown')                 return 'is not known or is not trustworthy';
    if (v === 'has_urgent_tone')           return 'has an urgent tone';
    if (v === 'contains_suspicious_links')  return 'contains hyperlinks with suspicious or malicious URLs';
    return '';
  }
  function buildSentence(isVal, who1, why1, who2, why2) {
    const isText = (isVal === 'is') ? 'is' : 'is not';
    const parts = [];
    if (who1 && why1) parts.push(`${whoText(who1)} <span class="emph">${whyText(why1)}</span>`);
    if (who2 && why2) parts.push(`${whoText(who2)} <span class="emph">${whyText(why2)}</span>`);
    const because = parts.length ? `, because ${parts.join(' and ')}` : '';
    return `The email you received <span class="emph">${isText}</span> a phishing email${because}.`;
  }

  function pluralize(n, word) { return `${n} ${word}${n === 1 ? '' : 's'}`; }

  function renderFriendlySummary(points, userSentence, expectedSentence) {
    const you = ($('.to')?.textContent || 'you').trim();
    summaryContainer.innerHTML = `
      <article class="friendly-summary">
        <header class="fs-head">
          <div class="fs-avatar">A</div>
          <div>
            <div class="fs-name">Amy <span class="fs-to">to ${you}</span></div>
            <div class="fs-title">You earned <span class="fs-points">${pluralize(points, 'point')}</span>! Thank you for your assistance!</div>
            <div class="fs-time">in a few seconds</div>
          </div>
        </header>
        <div class="fs-body">
          <div class="fs-block">
            <h4>This is the answer you provided:</h4>
            <div class="fs-quote">${userSentence}</div>
          </div>
          <div class="fs-block">
            <h4>And this is the answer we expected:</h4>
            <div class="fs-quote">${expectedSentence}</div>
          </div>
        </div>
      </article>
    `;
    // Scroll summary into view
    summaryContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  sendBtn?.addEventListener('click', async (e) => {
    e.preventDefault();
    if (sendBtn.disabled) return;

    const { score, userSentence, expectedSentence } = evaluate();

    if (ptsEl) ptsEl.textContent = String(score);
    renderFriendlySummary(score, userSentence, expectedSentence);

    // ---- IMPORTANT: send as form-urlencoded so PHP sees $_POST ----
    try {
      const body = new URLSearchParams({
        module_id: String(moduleId),
        points:    String(score),
        // you can pass these for logging if you decide to store them later
        // user_sentence: userSentence.replace(/<[^>]+>/g, ''),
        // expected_sentence: expectedSentence.replace(/<[^>]+>/g, '')
      });

      const res  = await fetch('ajax_submit_reply.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body
      });
      const data = await res.json().catch(() => ({}));

      if (data?.ok !== true) {
        console.warn('XP save did not confirm OK:', data);
      } else {
        // If PHP returns total_xp, reflect it anywhere you want, e.g. a badge:
        const xpBadges = document.querySelectorAll('[data-xp-badge]');
        xpBadges.forEach(el => { el.textContent = data.total_xp; });
      }

      // Prevent double-submits (optional)
      sendBtn.disabled = true;

    } catch (err) {
      console.error('Failed to save XP:', err);
    }
  });
})();
