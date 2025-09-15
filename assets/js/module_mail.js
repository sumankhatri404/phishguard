document.getElementById('replyBtn').addEventListener('click', () => {
    document.querySelector('.reply-box').classList.remove('hidden');
});

document.querySelectorAll('.ans').forEach(sel => {
    sel.addEventListener('change', () => {
        let allSelected = [...document.querySelectorAll('.ans')].every(s => s.value !== '');
        document.getElementById('sendBtn').disabled = !allSelected;
    });
});

document.getElementById('sendBtn').addEventListener('click', () => {
    const ans1 = document.getElementById('ans1').value;
    const ans2 = document.getElementById('ans2').value;
    const ans3 = document.getElementById('ans3').value;

    fetch('ajax_submit_reply.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `ans1=${ans1}&ans2=${ans2}&ans3=${ans3}`
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('feedback').innerHTML = data.feedback;
    });
});
