import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
// Flux's own script, bundled instead of @fluxScripts' PHP-served, uncompressed, parser-blocking tag. Imported before
// Livewire.start() so its `alpine:init` listener is in place when Alpine boots.
import '../../vendor/livewire/flux/dist/flux-lite.min.js';
import ui from '@alpinejs/ui';
import motionDirectives from './motion';
import components from './components';
import filterPanel from './filter-panel';
import book3d from './book-3d';
import bookScene from './book-scene';
import landing from './landing';
import versionChart from './version-chart';

// Plugins must be registered before Livewire.start() boots Alpine.
Alpine.plugin(ui);
Alpine.plugin(motionDirectives);
Alpine.plugin(components);
Alpine.plugin(filterPanel);
Alpine.plugin(book3d);
Alpine.plugin(bookScene);
Alpine.plugin(landing);
Alpine.plugin(versionChart);

Livewire.start();
