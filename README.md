<h1>Changes in Booking Controller</h1>
<ul>
    <li><b>Constructor Property Promotion:</b> The repository is now injected using PHP 8's constructor property promotion.</li>
    <li><b>Private Methods:</b> Extracted the repeated logic into private methods (`isAdmin`, `updateDistance`, and `updateBoj`) for better readability and single responsibility.</li>
    <li><b>Simplified Responses:</b> Directly returning responses to reduce redundancy.</li>
    <li><b>Type Hints:</b> Added type hints for better code clarity.</li>
    <li><b>Method Extraction:</b> Extracted logic from the `distanceFeed` method into private helper methods.</li>
    <li><b>Code Simplification: </b> Removed unnecessary comments and unused variables to keep the code clean and readable.</li>
</ul>
