let wc_gate_payment_gateway = 'wc_gate';//name of payment gateway
let wc_gate_settings  = window.wc.wcSettings.getSetting( wc_gate_payment_gateway+'_data', {} );
let wc_gate_label     = window.wp.htmlEntities.decodeEntities( wc_gate_settings.title ) || window.wp.i18n.__( 'TON', wc_gate_payment_gateway );
let wc_gate_content = () => {
    return window.wp.htmlEntities.decodeEntities( wc_gate_settings.description || '' );
};
let wc_gate_block_gateway = {
    name: wc_gate_payment_gateway,
    label: wc_gate_label,
    content: Object( window.wp.element.createElement )( wc_gate_content, null ),
    edit: Object( window.wp.element.createElement )( wc_gate_content, null ), 
    canMakePayment: () => true,
    ariaLabel: wc_gate_label,
    supports: {
        features: wc_gate_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod( wc_gate_block_gateway );