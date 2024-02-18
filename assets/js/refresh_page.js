var countdownMinutes = 1; // Set the countdown duration in minutes
var countdownSeconds = countdownMinutes * 60;

function updateCountdown() {
    var minutes = Math.floor(countdownSeconds / 60);
    var seconds = countdownSeconds % 60;
    var countdownDisplay = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;

    document.getElementById('countdown-timer').textContent = countdownDisplay;

    if (countdownSeconds <= 0) {
        location.reload();
    } else {
        countdownSeconds--;
        setTimeout(updateCountdown, 1000); // Update every second (1000 milliseconds)
    }
}

document.addEventListener("DOMContentLoaded", function() {
    updateCountdown();
});
