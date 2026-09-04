// Pure data transforms feeding the alternative chart types on Stock Charts.
// Heikin Ashi is a straightforward, standard formula. Renko/Kagi/Point &
// Figure are simplified-but-real implementations of textbook constructions
// (fixed box size / % reversal rather than user-tunable parameters) — none
// of chart.js's ecosystem has native support for these, so there's no
// off-the-shelf library backing them the way candlestick has.

/**
 * Standard Heikin Ashi smoothing — output is the same {trade_date, open_price,
 * high_price, low_price, close_price} shape as real prices, so it can be fed
 * straight into the existing candlestick renderer.
 */
export function toHeikinAshi(prices) {
  const result = []
  let prevOpen = null
  let prevClose = null

  for (const p of prices) {
    const o = Number(p.open_price)
    const h = Number(p.high_price)
    const l = Number(p.low_price)
    const c = Number(p.close_price)

    const haClose = (o + h + l + c) / 4
    const haOpen = prevOpen === null ? (o + c) / 2 : (prevOpen + prevClose) / 2
    const haHigh = Math.max(h, haOpen, haClose)
    const haLow = Math.min(l, haOpen, haClose)

    result.push({ trade_date: p.trade_date, open_price: haOpen, high_price: haHigh, low_price: haLow, close_price: haClose })
    prevOpen = haOpen
    prevClose = haClose
  }

  return result
}

/**
 * Renko bricks from closing price — a new brick is added only once price
 * has moved a full box size from the last brick's edge, so the output is
 * indexed by brick sequence, not by date (Renko is deliberately time-blind).
 * Box size defaults to ~1.5% of the average close over the window.
 */
export function toRenko(prices, boxSize = null) {
  if (prices.length === 0) return { bricks: [], boxSize: 0 }

  if (!boxSize) {
    const avg = prices.reduce((sum, p) => sum + Number(p.close_price), 0) / prices.length
    boxSize = avg * 0.015
  }

  const bricks = []
  let base = Number(prices[0].close_price)
  let direction = 0 // 0 = none yet, 1 = up, -1 = down

  for (const p of prices) {
    const close = Number(p.close_price)

    // A single day can cross multiple box thresholds — the while loop
    // emits one brick per threshold crossed, same as a real Renko chart.
    while (direction >= 0 && close >= base + boxSize) {
      bricks.push({ index: bricks.length, low: base, high: base + boxSize, up: true })
      base += boxSize
      direction = 1
    }
    while (direction <= 0 && close <= base - boxSize) {
      bricks.push({ index: bricks.length, low: base - boxSize, high: base, up: false })
      base -= boxSize
      direction = -1
    }
  }

  return { bricks, boxSize }
}

/**
 * Kagi line vertices from closing price. A line keeps extending while price
 * moves in its favor; once price reverses by the threshold %, a horizontal
 * "shoulder" point is inserted at the old extreme before starting the new
 * vertical run — that shoulder is what gives Kagi its right-angle look.
 */
export function toKagiPoints(prices, reversalPct = 0.03) {
  if (prices.length === 0) return []

  const points = []
  let lastExtreme = Number(prices[0].close_price)
  let direction = null // null until the first move establishes one

  points.push({ x: prices[0].trade_date, y: lastExtreme })

  for (let i = 1; i < prices.length; i++) {
    const date = prices[i].trade_date
    const close = Number(prices[i].close_price)

    if (direction === null) {
      if (close === lastExtreme) continue
      direction = close > lastExtreme ? 'up' : 'down'
      lastExtreme = close
      points.push({ x: date, y: close })
      continue
    }

    const favorable = direction === 'up' ? close >= lastExtreme : close <= lastExtreme

    if (favorable) {
      lastExtreme = close
      points[points.length - 1] = { x: date, y: close }
      continue
    }

    const movedPct = Math.abs(close - lastExtreme) / lastExtreme
    if (movedPct >= reversalPct) {
      points.push({ x: date, y: lastExtreme }) // shoulder at the old extreme
      direction = direction === 'up' ? 'down' : 'up'
      lastExtreme = close
      points.push({ x: date, y: close })
    }
  }

  return points
}

/**
 * Point & Figure columns from closing price — X columns for rising boxes, O
 * columns for falling, a new column only starts once price reverses by
 * `reversal` boxes (3 is the standard default). No time axis — columns are
 * purely sequential, exactly like a real P&F chart.
 */
export function toPointFigure(prices, boxSize = null, reversal = 3) {
  if (prices.length === 0) return { columns: [], boxSize: 0 }

  if (!boxSize) {
    const avg = prices.reduce((sum, p) => sum + Number(p.close_price), 0) / prices.length
    boxSize = Math.max(avg * 0.01, 0.01)
  }

  const boxOf = (price) => Math.round(price / boxSize)
  const columns = []
  let col = { type: 'X', boxes: [boxOf(Number(prices[0].close_price))] }

  for (let i = 1; i < prices.length; i++) {
    const box = boxOf(Number(prices[i].close_price))
    const top = col.boxes[col.boxes.length - 1]

    if (col.type === 'X') {
      if (box > top) {
        for (let b = top + 1; b <= box; b++) col.boxes.push(b)
      } else if (top - box >= reversal) {
        columns.push(col)
        col = { type: 'O', boxes: [] }
        for (let b = top - 1; b >= box; b--) col.boxes.push(b)
      }
    } else {
      if (box < top) {
        for (let b = top - 1; b >= box; b--) col.boxes.push(b)
      } else if (box - top >= reversal) {
        columns.push(col)
        col = { type: 'X', boxes: [] }
        for (let b = top + 1; b <= box; b++) col.boxes.push(b)
      }
    }
  }
  columns.push(col)

  return { columns, boxSize }
}
