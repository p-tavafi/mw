jQuery(document).ready(function($){
    
    $.plot("#opens-clicks-by-domain", $('#opens-clicks-by-domain').data('chartdata'), {
        series: {
            bars: {
                show: true,
                barWidth: 0.5,
                align: "center",
                lineWidth: 0,
                fill:.60
            }
        },
        xaxis: {
            mode: "categories",
            tickLength: 0
        }
    });
    
});