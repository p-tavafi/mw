jQuery(document).ready(function($){
    
    var plot = $.plot("#24hours-performance", $('#24hours-performance').data('chartdata'), {
        series: {
            lines: {
                show: true
            },
            points: {
                show: true
            }
        },
        grid: {
            hoverable: true,
            clickable: true,
            autoHighlight: true
        },
        
        xaxis: {
            mode: "time",
            timeformat: "%H:00%P"
        },
        crosshair: {
            mode: "x"
        }
    });

    $("<div id='tooltip'></div>").css({
        position: "absolute",
        display: "none",
        border: "1px solid #008ca9",
        color: '#000000',
        padding: "2px",
        "background-color": "#ebf6f8",
        opacity: 0.80
    }).appendTo("body");

    $("#24hours-performance").bind("plothover", function (event, pos, item) {

        if (item) {
            
            var y = item.datapoint[1].toFixed(0);
            $("#tooltip")
                .html(y + ' ' + item.series.label)
                .css({
                    top: item.pageY + 5, 
                    left: item.pageX + 5
                })
                .fadeIn(200);
            
        } else {
            
            $("#tooltip").hide();
        }

    });

    $("#24hours-performance").bind("plotclick", function (event, pos, item) {
        
        if (item) {
            plot.highlight(item.series, item.datapoint);
        }
        
    });

    var legends = $("#24hours-performance .legendLabel");
    var updateLegendTimeout = null;
    var latestPosition = null;

    function updateLegend() {

        updateLegendTimeout = null;

        var pos = latestPosition;

        var axes = plot.getAxes();
        if (pos.x < axes.xaxis.min || pos.x > axes.xaxis.max ||
            pos.y < axes.yaxis.min || pos.y > axes.yaxis.max) {
            return;
        }

        var i, j, dataset = plot.getData();
        for (i = 0; i < dataset.length; ++i) {

            var series = dataset[i];
            legends.eq(i).text(series.label);

            // Find the nearest points, x-wise

            for (j = 0; j < series.data.length; ++j) {
                if (series.data[j][0] > pos.x) {
                    break;
                }
            }

            // Now Interpolate
            var y,
                p1 = series.data[j - 1],
                p2 = series.data[j];

            if (p1 == null) {
                y = p2[1];
            } else if (p2 == null) {
                y = p1[1];
            } else {
                y = p1[1] + (p2[1] - p1[1]) * (pos.x - p1[0]) / (p2[0] - p1[0]);
            }

            legends.eq(i).text(series.label + ' ' + y.toFixed(0));
        }
    }

    $("#24hours-performance").bind("plothover",  function (event, pos, item) {
        latestPosition = pos;
        if (!updateLegendTimeout) {
            updateLegendTimeout = setTimeout(updateLegend, 50);
        }
    });

    
});