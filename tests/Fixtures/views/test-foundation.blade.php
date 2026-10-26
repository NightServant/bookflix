<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div x-data x-tabs>
            <div x-tabs:list>
                <button x-tabs:tab id="ui-tab">One</button>
            </div>
            <div x-tabs:panels>
                <div x-tabs:panel>First</div>
            </div>
        </div>

        <ul x-data x-motion-reveal id="reveal">
            <li>One</li>
            <li>Two</li>
        </ul>

        @livewireScriptConfig
    </body>
</html>
