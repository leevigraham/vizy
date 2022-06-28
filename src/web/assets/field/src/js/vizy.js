// CSS needs to be imported here as it's treated as a module
import '@/scss/style.scss';

//
// Start Vue Apps
//

if (typeof Craft.Vizy === typeof undefined) {
    Craft.Vizy = {};
}

import { createVueApp } from './config';

import VizyInput from './components/VizyInput.vue';
import VizySettings from './components/VizySettings.vue';

Craft.Vizy.Input = Garnish.Base.extend({
    init(idPrefix) {
        const app = createVueApp({
            components: {
                VizyInput,
            },

            methods: {
                onInputInit() {
                    // Not used here at root level, only for nested fields.
                    // Omitting would produce an error as it's referenced in template calls.
                },
            },
        });

        app.mount(`#${idPrefix}-field`);
    },
});

Craft.Vizy.Settings = Garnish.Base.extend({
    init(idPrefix, fieldData, settings) {
        const app = createVueApp({
            components: {
                VizySettings,
            },
            // document.querySelectorAll('.vizy-configurator').forEach((element) => {
            //     const fieldData = JSON.parse(element.getAttribute('data-field-data'));
            //     const settings = JSON.parse(element.getAttribute('data-settings'));

            data() {
                return {
                    fieldData,
                    settings,
                };
            },
        });

        app.mount(`.${idPrefix}-vizy-configurator`);
    },
});

// Re-broadcast the custom `vite-script-loaded` event so that we know that this module has loaded
// Needed because when <script> tags are appended to the DOM, the `onload` handlers
// are not executed, which happens in the field Settings page, and in slideouts
// Do this after the document is ready to ensure proper execution order
$(document).ready(() => {
    document.dispatchEvent(new CustomEvent('vite-script-loaded', { detail: { path: 'field/src/js/vizy.js' } }));
});