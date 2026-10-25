RiPro V5 inc/core 开源说明
================================

按原厂契约还原（可读，无授权远程校验）：
- class-zb-shop.php   订单表 CRUD / 已购判断 / pay_notfiy_callback / 回跳
- class-zb-pay.php    支付宝/微信/虎皮椒/讯虎/易支付/PayPal
- template-core.php   仅 require Shop + Pay

故意不还原：
- ZB_Lic（授权/激活/远程校验）—— 开源版不需要

方法清单与混淆版 Shop/Pay 公开 API 对齐（缺 0）。
