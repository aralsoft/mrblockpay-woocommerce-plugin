jQuery(document).ready(function($)
{
    var qrcode = new QRCode(document.getElementById("qrcode"), {
        width : 200,
        height : 200,
        correctLevel : QRCode.CorrectLevel.L,
        border: 1
    });

    var depositWallet = qrCodeParams.depositWallet;

    qrcode.makeCode(depositWallet);
});
