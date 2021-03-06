// Midas Server. Copyright Kitware SAS. Licensed under the Apache License 2.0.

/* global json */

var midas = midas || {};

$('#upgradeMIDAS').ajaxForm({
    beforeSubmit: validateUpgrade,
    success: successUpgrade
});

function validateUpgrade(formData, jqForm, options) {

}

function successUpgrade(responseText, statusText, xhr, form) {
    'use strict';
    var jsonResponse;
    try {
        jsonResponse = $.parseJSON(responseText);
    } catch (e) {
        alert("An error occured. Please check the logs.");
        return false;
    }
    if (jsonResponse === null) {
        midas.createNotice('Error', 4000);
        return;
    }
    if (jsonResponse[0]) {
        midas.createNotice(jsonResponse[1], 4000);
        window.location.replace(json.global.webroot + '/admin#ui-tabs-1');
        window.location.reload();
    } else {
        midas.createNotice(jsonResponse[1], 4000);
    }
}
