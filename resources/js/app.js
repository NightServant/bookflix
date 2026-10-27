import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import ui from '@alpinejs/ui';
import motionDirectives from './motion';
import components from './components';
import book3d from './book-3d';
import bookScene from './book-scene';

// Plugins must be registered before Livewire.start() boots Alpine.
Alpine.plugin(ui);
Alpine.plugin(motionDirectives);
Alpine.plugin(components);
Alpine.plugin(book3d);
Alpine.plugin(bookScene);

Livewire.start();
