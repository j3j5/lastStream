var d3 = require('d3')
	, http = require('http')
	, fs = require('fs')
	, jsdom = require('jsdom')
	, htmlStub = '<html><head></head><body><div id="svg-container"></div></body></html>' // html file skull with a container div for the d3 dataviz

var d3Chart = function (el) {

	var data = JSON.parse(fs.readFileSync('data/output/dgts.json', 'utf8'));

	var svgRoot = d3.select(el)
		.append('svg:svg')
		.attr('width', 600).attr('height', 300)
		.attr('version', '1.1')
		.attr('xmlns', 'http://www.w3.org/2000/svg')
		.attr('xmlns:xmlns:xlink', 'http://www.w3.org/1999/xlink');

	svgRoot.append('circle')
		.attr('cx', 150).attr('cy', 150).attr('r', 30).attr('fill', 'green')
		.attr('id', '1');

	return el.innerHTML;
};

// https://gist.github.com/Caged/6407459
http.createServer(function (req, res) {

	// Chrome automatically sends a requests for favicons
	// Looks like https://code.google.com/p/chromium/issues/detail?id=39402 isn't
	// fixed or this is a regression.
	if(req.url.indexOf('favicon.ico') != -1) {
		res.statusCode = 404
		return
	}

	// http://mango-is.com/blog/engineering/pre-render-d3-js-charts-at-server-side.html
	// pass the html stub to jsDom
	jsdom.env({ features : { QuerySelector : true }, html : htmlStub
		, done : function(errors, window) {
			// process the html document, like if we were at client side
			// code to generate the dataviz and process the resulting html file to be added here

			var el = window.document.querySelector('#svg-container');

			var svgStr = d3Chart(el);

			res.writeHead(200, {'Content-Type': 'image/svg+xml'})
			res.end(svgStr);
		}
	})
}).listen(1337, '127.0.0.1')