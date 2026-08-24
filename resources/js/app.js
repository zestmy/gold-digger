import './bootstrap';
import priceChart from './price-chart';

/**
 * Alpine components.
 *
 * Livewire 3 bundles Alpine and starts it from `@livewireScripts`, so components must be
 * registered on `alpine:init` rather than by importing Alpine here - importing a second
 * copy would give two instances racing over the same DOM.
 *
 * This file is loaded from `<head>` via @vite, which runs before Livewire's scripts at
 * the end of the body, so the listener is always in place before Alpine starts.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('priceChart', priceChart);
});
