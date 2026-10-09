// v-align-numbers — right-aligns every table column whose values are all
// numbers (prices, percentages, counts), header and footer (totals row) included. Runs after each
// render, so it keeps up with sorting, filtering and data loads.
//
// A column counts as numeric when every non-empty body cell looks like:
//   1234 · 1,234.50 · -3.2 · +3.2% · Rs. 1,234.50 · 1.8x · 2 (low sample)
// Blank cells and placeholders ("—", "N/A", "Rs. —", "—%") are ignored, so a
// price column with a few missing values still right-aligns. Anything else
// (dates, symbols, names, badges like "buy") keeps the
// whole column left-aligned.

const NUMERIC = /^[+\-−]?\s*(Rs\.?\s*)?[+\-−]?\d[\d,]*(\.\d+)?\s*(%|x|×)?(\s*\([^)]*\))?$/i
const PLACEHOLDER = /^(Rs\.?\s*)?(—|–|-|n\/a)?\s*%?$/i

function cellText(cell) {
  return cell.textContent.replace(/\s+/g, ' ').trim()
}

// Cells of one row keyed by column index, accounting for colspan.
function cellsByColumn(row) {
  const cells = new Map()
  let col = 0
  for (const cell of row.cells) {
    if (cell.colSpan === 1) cells.set(col, cell)
    col += cell.colSpan
  }
  return cells
}

function align(table) {
  const bodyRows = [...table.tBodies].flatMap((body) => [...body.rows])
  const headRows = table.tHead ? [...table.tHead.rows] : []
  const footRows = table.tFoot ? [...table.tFoot.rows] : [] // a totals row follows its column, not its own text
  const columns = new Map() // index -> body cells

  for (const row of bodyRows) {
    for (const [col, cell] of cellsByColumn(row)) {
      if (!columns.has(col)) columns.set(col, [])
      columns.get(col).push(cell)
    }
  }

  for (const [col, cells] of columns) {
    const values = cells.map(cellText).filter((text) => !PLACEHOLDER.test(text))
    const numeric = values.length > 0 && values.every((text) => NUMERIC.test(text))

    for (const cell of cells) cell.classList.toggle('num', numeric)
    for (const row of [...headRows, ...footRows]) cellsByColumn(row).get(col)?.classList.toggle('num', numeric)
  }
}

export default {
  mounted: align,
  updated: align,
}
