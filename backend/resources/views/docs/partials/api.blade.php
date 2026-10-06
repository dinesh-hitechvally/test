<h2>API reference</h2>
<p>
    One endpoint: <code>POST {{ $baseUrl }}/graphql</code> with a body of <code>{"query": "...", "variables": {...}}</code> and the header
    <code>Authorization: Bearer &lt;token&gt;</code>. Operations marked <span class="tag public">public</span> need no token; the rest are
    <span class="tag auth">auth</span>. Each operation expands to its arguments and a ready-to-run example. Field lists for the
    return types are under <a href="#types">Types</a>.
    To run any of these live, open the <a href="{{ url('console') }}"><strong>API console</strong></a>
    (<code>{{ $baseUrl }}/console</code>): it pre-fills each operation and has a <em>Check all</em> tab that runs every query once.
</p>

@foreach ($groups as $group => $ops)
    <h3 id="g-{{ Str::slug($group) }}">{{ $group }}</h3>
    @foreach ($ops as $op)
        <details class="op" id="op-{{ $op['name'] }}">
            <summary>
                <span class="tag {{ $op['kind'] }}">{{ $op['kind'] }}</span>
                <span class="opname">{{ $op['name'] }}</span>
                <span class="tag {{ $op['public'] ? 'public' : 'auth' }}">{{ $op['public'] ? 'public' : 'auth' }}</span>
                <span class="muted">{{ $op['description'] }}</span>
            </summary>
            <div class="body">
                <p><strong>Returns</strong> <code>{{ $op['returns'] }}</code></p>
                @if ($op['args'])
                    <table>
                        <tr><th>Argument</th><th>Type</th><th>Notes</th></tr>
                        @foreach ($op['args'] as $a)
                            <tr>
                                <td><code>{{ $a['name'] }}</code></td>
                                <td><code>{{ $a['type'] }}</code> @if ($a['required'])<span class="bad">required</span>@endif</td>
                                <td>{{ $a['description'] }} @if ($a['default'] !== null)<span class="muted">Default {{ $a['default'] }}.</span>@endif</td>
                            </tr>
                        @endforeach
                    </table>
                @else
                    <p class="muted">No arguments.</p>
                @endif
                <pre><code>{{ $op['example'] }}</code></pre>
            </div>
        </details>
    @endforeach
@endforeach
