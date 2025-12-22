(function() {
    'use strict';
    
    const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
    const { getSetting } = window.wc.wcSettings;
    const { decodeEntities } = wp.htmlEntities;
    const { createElement } = wp.element;
    
    const settings = getSetting('nwc_data', {});
    const label = decodeEntities(settings.title || 'Lightning Network');

    const Content = () => {
        return createElement('div', { className: 'wc-nwc-payment-content' },
            decodeEntities(settings.description || '')
        );
    };

    const Label = (props) => {
        const { PaymentMethodLabel } = props.components;
        
        return createElement(
            'div',
            { style: { display: 'flex', alignItems: 'center', gap: '10px' } },
            settings.icon && createElement('img', {
                src: settings.icon,
                alt: 'Lightning',
                style: { width: '24px', height: '24px' }
            }),
            createElement(PaymentMethodLabel, { text: label })
        );
    };

    registerPaymentMethod({
        name: 'nwc',
        label: createElement(Label, {}),
        content: createElement(Content, {}),
        edit: createElement(Content, {}),
        canMakePayment: () => true,
        ariaLabel: label,
        supports: {
            features: settings.supports || []
        }
    });
})();
