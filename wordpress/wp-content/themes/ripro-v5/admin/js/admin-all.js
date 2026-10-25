jQuery(document).ready(function ($) {
    $(document).on('click', ".rest_mpweixin_menu", function (event) {
        event.preventDefault()
        $.post(zb.ajax_url, {
            "action": "zb_rest_mpweixin_menu",
        }, function (res) {
            // console.log(data)
            if (res.status == 1) {
                alert('公众号菜单更新成功');
            } else {
                alert(res.msg);
            }
        });
    });

});