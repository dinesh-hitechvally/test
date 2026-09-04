// Static beginner-facing explainer content for the Learn section and the
// "How Signals Work" page. Plain data so the topic pages can share one
// rendering component instead of duplicating markup per topic.
export const learnTopics = {
  basics: {
    title: 'Stock Market Basics',
    sections: [
      {
        heading: 'What is NEPSE?',
        body: 'The Nepal Stock Exchange (NEPSE) is the only securities exchange in Nepal. When you "buy a stock," you\'re buying a small ownership share in a listed company — a bank, hydropower developer, insurer, and so on — through a licensed broker.',
      },
      {
        heading: 'How trading works',
        body: 'NEPSE trades Sunday-adjacent weekdays (Monday-Friday in the current schedule this app follows), with prices moving throughout the session based on buy/sell orders matched by the exchange. The closing price each day becomes the reference for the next day\'s move.',
      },
      {
        heading: 'Why prices move',
        body: 'A stock\'s price reflects what buyers are willing to pay and sellers are willing to accept right now — driven by company earnings and dividends, sector-wide news, interest rates, overall market sentiment, and sometimes pure supply and demand from retail trading activity.',
      },
      {
        heading: 'Key terms to know first',
        body: 'LTP (Last Traded Price), Volume (shares traded), Turnover (value traded, i.e. price × volume), 52-Week High/Low, and Market Cap (share price × total shares) are the numbers you\'ll see everywhere in this app — the Market and Stock Detail pages surface all of them.',
      },
    ],
  },
  'reading-signals': {
    title: 'How to Read Signals',
    sections: [
      {
        heading: 'What a signal is (and isn\'t)',
        body: 'A signal — Strong Buy, Buy, Hold, Sell, Strong Sell — is a rule-based read on a stock\'s technical indicators as of the last close. It is not a prediction, a guarantee, or financial advice — it\'s a structured way of summarizing "what do the indicators say right now."',
      },
      {
        heading: 'Where it comes from',
        body: 'Each day, the signal engine checks a fixed set of conditions — a golden/death cross (SMA50 vs SMA200), a short-term SMA20/50 cross, RSI overbought/oversold, a MACD crossover, and price touching the outer Bollinger Bands — and scores how many point bullish vs. bearish. The score converts to the label you see.',
      },
      {
        heading: 'Reading the "Reasons"',
        body: 'Every signal lists the specific reasons behind its score (e.g. "RSI 28.4 — oversold", "Golden cross: SMA50 crossed above SMA200"). Read these before acting on the label alone — two "Buy" signals for different reasons can mean very different things about a stock\'s situation.',
      },
      {
        heading: 'Use the Rule Scanner to go deeper',
        body: 'If you want to search for a specific condition rather than trust the blended score, the Rule Scanner under Reports lets you pick exactly which conditions matter to you (e.g. only RSI oversold + MACD bullish cross) and find every stock matching them today.',
      },
    ],
  },
  indicators: {
    title: 'Understanding Technical Indicators',
    sections: [
      {
        heading: 'Moving Averages (SMA/EMA)',
        body: 'A moving average smooths out day-to-day noise by averaging closing prices over a period (20/50/100/200 days are shown throughout this app). Price above a rising moving average is generally read as bullish; below a falling one, bearish. When shorter averages cross above longer ones, that\'s a "golden cross" — a classic bullish signal (the reverse is a "death cross").',
      },
      {
        heading: 'RSI (Relative Strength Index)',
        body: 'RSI measures how fast and how far price has moved recently, on a 0-100 scale. Above 70 is traditionally "overbought" (the rally may be overextended), below 30 is "oversold" (the selloff may be overextended). It doesn\'t mean an immediate reversal — just that the recent move has been unusually strong.',
      },
      {
        heading: 'MACD',
        body: 'MACD tracks the relationship between two moving averages (12-day and 26-day EMA) via a MACD line and a signal line. When the MACD line crosses above its signal line, that\'s read as bullish momentum; crossing below is bearish. The histogram (the gap between the two lines) shows whether that momentum is strengthening or fading.',
      },
      {
        heading: 'Bollinger Bands',
        body: 'Bollinger Bands wrap a moving average with an upper and lower band based on recent volatility. Price hugging the upper band suggests strength (or overextension); touching the lower band suggests weakness (or a potential rebound). Bands squeezing tight often precede a bigger move, in either direction.',
      },
      {
        heading: 'Support & Resistance',
        body: 'Support is a price level where a stock has repeatedly stopped falling and bounced; resistance is where it has repeatedly stopped rising and pulled back. They\'re not exact lines — think of them as zones where buying or selling pressure has historically shown up.',
      },
    ],
  },
  glossary: {
    title: 'Glossary of Terms',
    sections: [
      { heading: 'LTP', body: 'Last Traded Price — the most recent price a share actually traded at.' },
      { heading: 'Turnover', body: 'The total value traded (price × quantity) for a stock or the market on a given day.' },
      { heading: '52-Week High/Low', body: 'The highest and lowest closing prices over the trailing 365 days.' },
      { heading: 'Market Cap', body: 'Total value of a company\'s shares: share price × total shares outstanding.' },
      { heading: 'Bonus Share', body: 'Free additional shares issued to existing shareholders instead of (or alongside) cash, expressed as a %. Increases share count, dilutes price proportionally.' },
      { heading: 'Right Share', body: 'An offer letting existing shareholders buy additional new shares, usually below market price, in a fixed ratio to what they already hold.' },
      { heading: 'Book Closure Date', body: 'The date on which the company "freezes" its shareholder list to determine who qualifies for a declared dividend or bonus share.' },
      { heading: 'P&L (Profit & Loss)', body: 'Unrealized P&L is the paper gain/loss on shares you still hold; realized P&L is the actual gain/loss booked once you sell.' },
      { heading: 'Weighted Average Cost', body: 'The method this app uses to track your holding\'s cost basis — each buy blends into a single running average cost per share, which every sell is measured against.' },
      { heading: 'Stop-Loss', body: 'A price level below your entry where you plan to exit a losing position to limit further downside.' },
      { heading: 'Risk/Reward Ratio', body: 'How much you stand to gain (distance to your target) versus how much you\'re risking (distance to your stop-loss) on a trade. 1:2 or better is generally considered attractive.' },
    ],
  },
  'signals-explainer': {
    title: 'How Signals Work',
    sections: [
      {
        heading: 'The short version',
        body: 'Every stock gets a daily Buy/Sell/Hold signal from a transparent, rule-based scoring system — not a black-box model. You can see exactly which conditions fired for any stock\'s signal, and use the Rule Scanner to search by specific conditions instead of the blended label.',
      },
      {
        heading: 'What feeds the score',
        body: 'Five conditions, each contributing to the score: a long-term moving-average cross (weighted highest), a short-term moving-average cross, RSI extremes, a MACD crossover, and price touching the outer Bollinger Bands. The combined score is classified into Strong Buy / Buy / Hold / Sell / Strong Sell.',
      },
      {
        heading: 'What it is not',
        body: 'This system is honest about its limits: the rule-based signals are heuristics, not backtested-and-proven strategies (that validation work — measuring historical win rate per rule — isn\'t built yet). Separately, the ML direction predictor and the statistical (Holt) forecast on each Stock Detail page ARE backtested, and their real, sometimes unflattering accuracy is reported directly on those pages rather than hidden.',
      },
      {
        heading: 'How to use it responsibly',
        body: 'Treat a signal as one input, not a verdict. Read the listed reasons, check the Technical Analysis report for the fuller picture (support/resistance, trend, volume), and never treat any of this as financial advice.',
      },
    ],
  },
}
