import { Alpine, Livewire } from '../../vendor/livewire/livewire/dist/livewire.esm';
import ui from '@alpinejs/ui';
import motionDirectives from './motion';

// Plugins must be registered before Livewire.start() boots Alpine.
Alpine.plugin(ui);
Alpine.plugin(motionDirectives);

Livewire.start();
