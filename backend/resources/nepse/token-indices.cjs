// Executes nepalstock.com's own css.wasm (a disguised token-signing module)
// to compute the character-index positions used to splice their raw
// accessToken/refreshToken into the real usable ones. This is the one place
// in the app that needs actual WebAssembly execution, not just arithmetic —
// the salt->index mapping is defined by the WASM binary itself, not a
// documented formula. Invoked by App\Services\MarketData\NepalStockTokenService.
//
// Usage: node token-indices.js <wasmPath> <salt1> <salt2> <salt3> <salt4> <salt5>
// Prints: {"access":{"n":.,"l":.,"o":.,"p":.,"q":.},"refresh":{"a":.,"b":.,"c":.,"d":.,"e":.}}

const fs = require('fs')

async function main() {
  const wasmPath = process.argv[2]
  const [s1, s2, s3, s4, s5] = process.argv.slice(3).map(Number)

  if (!wasmPath || [s1, s2, s3, s4, s5].some((n) => Number.isNaN(n))) {
    throw new Error('usage: node token-indices.js <wasmPath> <salt1> <salt2> <salt3> <salt4> <salt5>')
  }

  const bytes = fs.readFileSync(wasmPath)
  const { instance } = await WebAssembly.instantiate(bytes, {})

  // Exact permutations ported from basic-bgnr/NepseUnofficialApi's
  // TokenParser.parse_token_response (verified against the live site).
  const access = {
    n: instance.exports.cdx(s1, s2, s3, s4, s5),
    l: instance.exports.rdx(s1, s2, s4, s3, s5),
    o: instance.exports.bdx(s1, s2, s4, s3, s5),
    p: instance.exports.ndx(s1, s2, s4, s3, s5),
    q: instance.exports.mdx(s1, s2, s4, s3, s5),
  }

  const refresh = {
    a: instance.exports.cdx(s2, s1, s3, s5, s4),
    b: instance.exports.rdx(s2, s1, s3, s4, s5),
    c: instance.exports.bdx(s2, s1, s4, s3, s5),
    d: instance.exports.ndx(s2, s1, s4, s3, s5),
    e: instance.exports.mdx(s2, s1, s4, s3, s5),
  }

  process.stdout.write(JSON.stringify({ access, refresh }))
}

main().catch((e) => {
  process.stderr.write('ERROR: ' + e.message)
  process.exit(1)
})
