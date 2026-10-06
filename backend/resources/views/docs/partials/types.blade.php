<h2>Types</h2>
<p>
    Every object, input and enum the API returns or accepts. A type ending in <code>!</code> is never null; <code>[X!]!</code> is a
    list. Decimal columns are declared <code>String</code> (see <a href="#conventions">conventions</a>).
</p>

@foreach ($types as $type)
    <details class="op" id="t-{{ $type['name'] }}">
        <summary>
            <span class="tag type">{{ $type['kind'] }}</span>
            <span class="opname">{{ $type['name'] }}</span>
            <span class="muted">{{ $type['description'] }}</span>
        </summary>
        <div class="body">
            <table>
                <tr><th>Field</th><th>Type</th><th>Notes</th></tr>
                @foreach ($type['fields'] as $f)
                    <tr>
                        <td><code>{{ $f['name'] }}</code></td>
                        <td>@if ($f['type'])<code>{{ $f['type'] }}</code>@endif</td>
                        <td>{{ $f['description'] }}</td>
                    </tr>
                @endforeach
            </table>
        </div>
    </details>
@endforeach
